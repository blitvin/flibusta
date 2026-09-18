<?php
/**
 * Invalidates every cached book entry by bumping the library generation.
 *
 * Run from the shell scripts that rebuild library content - app_import_sql.sh
 * (dump import) and app_reindex.sh (archive rescan) - after the DB work is
 * finished. A no-op, and a success, when Redis is not configured.
 *
 *     php /tools/cache_bump.php
 */

if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	die("CLI only\n");
}

require_once(getenv('FLIBUSTA_APP_ROOT') ?: '/application/') . 'cache.php';

if (!flibusta_redis_enabled()) {
	echo "cache_bump: Redis is not configured, nothing to invalidate\n";
	exit(0);
}
if (flibusta_redis() === null) {
	// Not fatal for the caller: the import itself succeeded, and stale entries
	// expire on their own. Still worth a loud line in the log.
	fwrite(STDERR, "cache_bump: Redis is configured but unreachable - book cache entries were NOT invalidated\n");
	exit(0);
}

echo 'cache_bump: library generation is now ' . lib_generation_bump() . "\n";
