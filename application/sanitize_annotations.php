<?php
/**
 * Sanitize stored annotation HTML in place.
 *
 * Runs as part of tools/app_import_sql.sh (after the dumps are loaded) and can
 * also be run standalone as a one-time backfill against an existing DB:
 *
 *     php /application/sanitize_annotations.php
 *
 * It rewrites libbannotations.body and libaannotations.body through the
 * allow-list sanitizer so every later read (including the raw $full render
 * path and any page cache) is safe. Idempotent: re-running only touches rows
 * whose sanitized form differs from what is stored.
 *
 * Correctness note: app_import_sql.sh holds the exclusive DB update lock while
 * this runs, so no concurrent writes occur and ctid is a stable row handle for
 * the duration of the transaction.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("CLI only\n");
}

define('ROOT_PATH', getenv('FLIBUSTA_APP_ROOT') ?: '/application/');
require ROOT_PATH . 'dbinit.php';        // provides $dbh from env + secret file
require ROOT_PATH . 'sanitizer.php';     // provides flibusta_sanitize_annotation_html()

if (!isset($dbh) || !($dbh instanceof PDO)) {
    fwrite(STDERR, "sanitize_annotations: no DB handle\n");
    exit(1);
}

/**
 * Stream one table with a server-side cursor and update changed rows in
 * batches. Keyed on ctid (safe under the import lock), so no dependency on a
 * declared primary key.
 */
function sanitize_table(PDO $dbh, string $table, string $col): array {
    $scanned = 0;
    $changed = 0;
    $batch   = 500;

    $dbh->beginTransaction();
    try {
        $dbh->exec(
            "DECLARE ann_cur NO SCROLL CURSOR FOR " .
            "SELECT ctid, $col AS body FROM $table " .
            "WHERE $col IS NOT NULL AND $col <> ''"
        );
        $upd = $dbh->prepare(
            "UPDATE $table SET $col = :b WHERE ctid = CAST(:c AS tid)"
        );

        while (true) {
            $rows = $dbh->query("FETCH $batch FROM ann_cur")
                        ->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                $scanned++;
                $clean = flibusta_sanitize_annotation_html($row['body']);
                if ($clean !== $row['body']) {
                    $upd->execute([':b' => $clean, ':c' => $row['ctid']]);
                    $changed++;
                }
            }
        }

        $dbh->exec("CLOSE ann_cur");
        $dbh->commit();
    } catch (Throwable $e) {
        $dbh->rollBack();
        throw $e;
    }
    return [$scanned, $changed];
}

$engine = class_exists('HTMLPurifier') ? 'HTML Purifier' : 'DOMDocument allow-list';
fwrite(STDERR, "sanitize_annotations: engine = $engine\n");

$targets = [
    ['libbannotations', 'body'],
    ['libaannotations', 'body'],
];

$exit = 0;
foreach ($targets as [$table, $col]) {
    try {
        [$scanned, $changed] = sanitize_table($dbh, $table, $col);
        fwrite(STDERR, "sanitize_annotations: $table — scanned $scanned, rewrote $changed\n");
    } catch (Throwable $e) {
        fwrite(STDERR, "sanitize_annotations: $table FAILED — " . $e->getMessage() . "\n");
        $exit = 1;
    }
}

exit($exit);
