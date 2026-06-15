#!/bin/sh

ADMINOPLOCKFILE=/cache/locks/adminop.lock

FLIBUSTA_URL="${FLIBUSTA_URL:-https://flibusta.is}"
echo "getcovers.sh : start running" >&2

exec 199> "$ADMINOPLOCKFILE"
if ! flock -n 199; then
  echo "getcovers.sh : failed to obtain admin op lock" >&2
  exit 1;
fi




echo "Обновление lib.a.attached.zip"
/tools/refresh_file.sh lib.a.attached.zip "${FLIBUSTA_URL}/sql/lib.a.attached.zip" /cache/ 'unzip -t'
echo "Обновление lib.b.attached.zip"
/tools/refresh_file.sh lib.b.attached.zip "${FLIBUSTA_URL}/sql/lib.b.attached.zip" /cache/ 'unzip -t'
echo Обновление закончено
date > /cache/timestamps/getcovers
echo "getcovers.sh : finished" >&2
exec 199>&-
