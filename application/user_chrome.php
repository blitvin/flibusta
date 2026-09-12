<?php
// Per-visitor page chrome, kept out of the cached page body.
//
// The navigation bar shows the logged-in user's name and, for admins, extra
// menu entries. That is the only per-user content in most pages, and leaving it
// in the body would force a separate cache entry per user for pages that are
// otherwise byte-identical - including the fb2 reader page, the most expensive
// page in the app to render.
//
// So renderer.php emits placeholders, the cached copy stores those placeholders,
// and the output filter substitutes the current visitor's chrome on the way out
// (see flib_output_filter, installed in public/index.php). This happens for
// cache hits, cache misses and die() paths alike, and needs no database access
// and no client-side JavaScript - the nav is correct in the first byte the
// browser sees, with no flash or layout shift.
//
// Note this is deliberately *not* done by string-replacing the rendered name the
// way CSRF tokens are handled: a username is not unique, and an author in a book
// listing who happens to share a visitor's name would be corrupted by such a
// swap. Placeholders are emitted at the point of rendering instead.

define('CHROME_NAV_ITEMS_PLACEHOLDER', '@@FLIBUSTA_NAV_ITEMS@@');
define('CHROME_USER_CHIP_PLACEHOLDER', '@@FLIBUSTA_USER_CHIP@@');

// Admin-only and logged-in-only entries of the main menu.
// $activeMod is the routed module, used to mark the current tab.
function render_nav_items(string $webroot, string $activeMod): string {
	$out = '';
	if (!empty($_SESSION['is_admin'])) {
		$c6 = $activeMod === 'service' ? 'active' : '';
		$c7 = $activeMod === 'users'   ? 'active' : '';
		$c8 = $activeMod === 'addbook' ? 'active' : '';
		$out .= <<< __HTML
			<li class="nav-item $c6"><a title="" class="nav-link" href="$webroot/service/">Сервис</a></li>
			<li class="nav-item $c7"><a title="" class="nav-link" href="$webroot/users/">Пользователи</a></li>
			<li class="nav-item $c8"><a title="" class="nav-link" href="$webroot/addbook/">Добавить</a></li>
__HTML;
	}
	if (!empty($_SESSION['user_id'])) {
		$s9 = $activeMod === 'settings' ? 'active' : '';
		$out .= <<< __HTML
			<li class="nav-item $s9"><a title="" class="nav-link" href="$webroot/settings/">Настройки</a></li>
__HTML;
	}
	return $out;
}

// The username badge shown next to the "switch user" button.
function render_user_chip(): string {
	if (empty($_SESSION['username'])) {
		return '';
	}
	$name = htmlspecialchars((string)$_SESSION['username'], ENT_QUOTES, 'UTF-8');
	$class = !empty($_SESSION['is_admin']) ? 'btn btn-warning btn-sm me-2' : 'btn btn-outline-info btn-sm me-2';
	return "<span class='$class'>$name</span>";
}

// Substitutes both placeholders for the current visitor. A no-op (single strpos)
// on responses that contain neither.
function user_chrome_fill(string $body): string {
	if (strpos($body, '@@FLIBUSTA_') === false) {
		return $body;
	}
	global $webroot, $url;
	$activeMod = isset($url->mod) ? (string)$url->mod : '';
	$root = is_string($webroot ?? null) ? $webroot : '';
	if (strpos($body, CHROME_NAV_ITEMS_PLACEHOLDER) !== false) {
		$body = str_replace(CHROME_NAV_ITEMS_PLACEHOLDER, render_nav_items($root, $activeMod), $body);
	}
	if (strpos($body, CHROME_USER_CHIP_PLACEHOLDER) !== false) {
		$body = str_replace(CHROME_USER_CHIP_PLACEHOLDER, render_user_chip(), $body);
	}
	return $body;
}

// Output-buffer callback installed by public/index.php. Runs once, on flush, for
// every response the front controller produces - so placeholders are resolved
// whether the page came from the cache, was just rendered, or the request ended
// in die() half way through.
function flib_output_filter(string $buffer): string {
	if (function_exists('csrf_cache_decode')) {
		$buffer = csrf_cache_decode($buffer);
	}
	return user_chrome_fill($buffer);
}
