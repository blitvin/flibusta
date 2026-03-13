#!/bin/sh
URL="http://flibusta.is/daily/"
DEST_DIR="/cache/local/"


ADMINOPLOCKFILE=/cache/adminop.lock

echo "update_daily.sh : start running" >&2

exec 199> "$ADMINOPLOCKFILE"
if ! flock -n 199; then
  echo "update_daily.sh : failed to obtain admin op lock" >&2
  exit 1;
fi

echo "Running update_daily.sh"
mkdir -p "$DEST_DIR"
curl -s "$URL" > /cache/tmp/page.html
grep -Eo 'href="f\.(fb2|n)\.[0-9\-]+\.zip"' /cache/tmp/page.html | sed 's/href="//;s/"//' > /cache/tmp/links.txt

while IFS= read -r file; do
#    wget -c -P "$DEST_DIR" "$URL$file"
    /tools/refresh_file.sh $file $URL $DEST_DIR
done < /cache/tmp/links.txt

rm /cache/tmp/page.html /cache/tmp/links.txt
echo "update_daily.sh : finished" >&2
date > /cache/timestamps/update_daily
exec 199>&-