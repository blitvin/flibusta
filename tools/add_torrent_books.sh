#!/bin/sh

# Add book ZIPs to the local library from a torrent, selecting only files that
# follow the flibusta naming convention and are not already present.
#
# $1 - unique temporary working directory (created by caller, removed here)
# $2 - torrent source: either a magnet URI or a path to a .torrent file
#
# Both actions (magnet link / uploaded .torrent file) share this script; only
# $2 differs. $2 is passed as a single argument and always quoted, so shell
# metacharacters in a magnet link are harmless.

ADMINOPLOCKFILE=/cache/locks/adminop.lock
LIBRARY_PATH=/flibusta/
LOCAL_LIBRARY_PATH=/cache/local/

# accepted names: [d.|f.]?(fb2|usr|n)[.-]<number>-<number>.zip
# (also tolerates the double-dash seen in some real torrent names)
NAME_PATTERN='^(d\.|f\.)?(fb2|usr|n)[.-][0-9]+-+[0-9]+\.zip$'

# Abort aria2c if the download speed stays at 0 for this many seconds. Guards
# against a dead torrent (no seeders) hanging forever while holding the admin
# lock — applied to both the metadata/listing fetch and the download.
BT_STOP_TIMEOUT=600

TMPDIR="$1"
SOURCE="$2"

echo "add_torrent_books.sh : start running" >&2

cleanup() {
  [ -n "$TMPDIR" ] && [ -d "$TMPDIR" ] && rm -rf "$TMPDIR"
}

if [ -z "$TMPDIR" ] || [ -z "$SOURCE" ]; then
  echo "Внутренняя ошибка: не заданы параметры операции"
  echo "add_torrent_books.sh : missing arguments" >&2
  cleanup
  exit 124
fi

exec 199> "$ADMINOPLOCKFILE"
if ! flock -n 199; then
  echo "Не удалось получить блокировку: другая операция уже выполняется"
  echo "add_torrent_books.sh : failed to obtain admin op lock" >&2
  cleanup
  exit 1
fi

if [ ! -d "$TMPDIR" ]; then
  echo "Внутренняя ошибка: временный каталог не существует"
  echo "add_torrent_books.sh : temp dir missing" >&2
  exit 2
fi

echo "Чтение списка файлов торрента..."
LISTING="${TMPDIR}/listing.txt"
if ! aria2c -S --bt-stop-timeout="$BT_STOP_TIMEOUT" "$SOURCE" 2>&1 | tr -d '\r' > "$LISTING"; then
  echo "Не удалось прочитать торрент (aria2c -S завершился с ошибкой)"
  echo "add_torrent_books.sh : aria2c -S failed" >&2
  cat "$LISTING"
  cleanup
  exit 3
fi

# Select files: matching the convention and absent from both libraries.
# Accumulate --index-out=<idx>=<name> args in the positional parameters ($@)
# and the aria2 --select-file id list in $SELECT. $SEEN is the de-duplicated
# list of wanted base names, reused later to move the downloaded files.
set --
SELECT=""
SEEN=""
COUNT=0
while IFS= read -r line; do
  case "$line" in
    *"|"*) : ;;      # candidate "idx|path" line
    *) continue ;;
  esac
  idx=${line%%|*}
  idx=$(echo "$idx" | tr -d ' ')
  case "$idx" in
    ''|*[!0-9]*) continue ;;   # size line or header, not a numeric index
  esac
  path=${line#*|}
  name=${path##*/}
  # skip if it does not match the naming convention
  echo "$name" | grep -Eq "$NAME_PATTERN" || continue
  # skip if it already exists in either library
  if [ -f "${LIBRARY_PATH}${name}" ] || [ -f "${LOCAL_LIBRARY_PATH}${name}" ]; then
    echo "Пропуск $name — уже есть в библиотеке"
    continue
  fi
  # skip duplicate base names within the same torrent
  case " $SEEN " in
    *" $name "*) continue ;;
  esac
  SEEN="$SEEN $name"
  set -- "$@" "--index-out=${idx}=${name}"
  if [ -z "$SELECT" ]; then SELECT="$idx"; else SELECT="${SELECT},${idx}"; fi
  COUNT=$((COUNT + 1))
  echo "К загрузке: $name (индекс $idx)"
done < "$LISTING"
rm -f "$LISTING"

if [ "$COUNT" -eq 0 ]; then
  echo "Новых подходящих файлов в торренте не найдено."
  echo "add_torrent_books.sh : nothing to download" >&2
  cleanup
  exit 0
fi

echo "Загрузка $COUNT файл(ов)..."
aria2c --dir="$TMPDIR" --select-file="$SELECT" --seed-time=0 \
       --bt-stop-timeout="$BT_STOP_TIMEOUT" \
       --show-console-readout=false --summary-interval=30 "$@" "$SOURCE"
ariares=$?
if [ "$ariares" != "0" ]; then
  echo "Предупреждение: aria2c завершился с кодом $ariares, проверяю скачанные файлы"
  echo "add_torrent_books.sh : aria2c download returned $ariares" >&2
fi

mkdir -p "$LOCAL_LIBRARY_PATH"
MOVED=0
FAILED=0
for name in $SEEN; do
  f=$(find "$TMPDIR" -type f -name "$name" 2>/dev/null | head -n 1)
  if [ -z "$f" ]; then
    echo "Файл $name не был скачан, пропущен"
    FAILED=$((FAILED + 1))
    continue
  fi
  if ! unzip -t "$f" > /dev/null 2>&1; then
    echo "Файл $name повреждён (не ZIP), пропущен"
    FAILED=$((FAILED + 1))
    continue
  fi
  if [ -f "${LIBRARY_PATH}${name}" ] || [ -f "${LOCAL_LIBRARY_PATH}${name}" ]; then
    echo "Файл $name тем временем появился в библиотеке, пропущен"
    FAILED=$((FAILED + 1))
    continue
  fi
  if mv "$f" "${LOCAL_LIBRARY_PATH}${name}"; then
    echo "Добавлен $name"
    MOVED=$((MOVED + 1))
  else
    echo "Не удалось переместить $name"
    FAILED=$((FAILED + 1))
  fi
done

cleanup
echo "Готово: добавлено $MOVED, пропущено/с ошибками $FAILED."
if [ "$MOVED" -gt 0 ]; then
  echo "Чтобы новые книги стали доступны, запустите \"Сканирование ZIP\"."
fi
echo "add_torrent_books.sh : finished" >&2
exec 199>&-
