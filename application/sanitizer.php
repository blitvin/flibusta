<?php
/**
 * Allow-list HTML sanitizer for rich-text annotation fields
 * (libbannotations.body, libaannotations.body).
 *
 * Policy (default-deny — anything not listed is removed):
 *   Allowed tags:   p br em strong i b u ul ol li blockquote h4 h5 h6 span a
 *   Allowed attrs:  href on <a> only, and only http/https/mailto or a safe
 *                   relative URL. On sanitized <a> we force
 *                   rel="nofollow noopener noreferrer" target="_blank".
 * Removed: <script>, <style>, <iframe>, event handlers (on*), style="",
 *   javascript:/data: URLs, unknown tags/attributes.
 *
 * Two backends, identical policy:
 *   - HTML Purifier if it is installed (Composer autoload present).
 *   - Otherwise a dependency-free DOMDocument allow-list walk (ext-dom,
 *     which ships by default with PHP). Use this to sanitize once at import
 *     time (tools/sanitize_annotations.php) or on every render.
 *
 * Both callers should pass the raw stored HTML; callers must NOT additionally
 * htmlspecialchars() the result (that would double-escape the kept markup).
 */

if (!function_exists('flibusta_sanitize_annotation_html')) {

/** Public entry point. */
function flibusta_sanitize_annotation_html(?string $html): string {
    $html = (string)$html;
    if ($html === '') {
        return '';
    }

    // Prefer HTML Purifier when available (optional upgrade).
    if (class_exists('HTMLPurifier')) {
        static $purifier = null;
        if ($purifier === null) {
            $config = HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed',
                'p,br,em,strong,i,b,u,ul,ol,li,blockquote,h4,h5,h6,span,a[href|rel|target]');
            $config->set('URI.AllowedSchemes',
                ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('HTML.TargetBlank', true);
            $config->set('HTML.Nofollow', true);
            $config->set('AutoFormat.RemoveEmpty', true);
            // Avoid needing a writable cache dir in the container.
            $config->set('Cache.DefinitionImpl', null);
            $purifier = new HTMLPurifier($config);
        }
        return $purifier->purify($html);
    }

    return flibusta_sanitize_html_dom($html);
}

/** Dependency-free fallback using DOMDocument. */
function flibusta_sanitize_html_dom(string $html): string {
    static $allowed = [
        'p','br','em','strong','i','b','u','ul','ol','li',
        'blockquote','h4','h5','h6','span','a',
    ];

    if (!class_exists('DOMDocument')) {
        // No DOM extension at all: strip to plain, escaped text.
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    // The <?xml encoding> prefix forces UTF-8 parsing without needing mbstring.
    $prefix = '<?xml encoding="UTF-8">';
    libxml_use_internal_errors(true);
    $ok = $doc->loadHTML(
        $prefix . '<body>' . $html . '</body>',
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );
    libxml_clear_errors();
    if (!$ok) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    $body = $doc->getElementsByTagName('body')->item(0);
    if ($body === null) {
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    $xpath = new DOMXPath($doc);
    // Document order => parents before children. Snapshot the list because we
    // mutate the tree while iterating.
    foreach (iterator_to_array($xpath->query('.//*', $body)) as $el) {
        // Skip nodes already detached by an earlier removal.
        if ($el->parentNode === null) {
            continue;
        }
        $tag = strtolower($el->nodeName);

        if (!in_array($tag, $allowed, true)) {
            // Unwrap: promote children in place, then drop the disallowed tag.
            // For <script>alert(1)</script> the child text "alert(1)" is
            // promoted as inert visible text — never executed.
            $parent = $el->parentNode;
            while ($el->firstChild) {
                $parent->insertBefore($el->firstChild, $el);
            }
            $parent->removeChild($el);
            continue;
        }

        // Strip every attribute except a validated href on <a>.
        if ($el->hasAttributes()) {
            foreach (iterator_to_array($el->attributes) as $attr) {
                $name = strtolower($attr->name);
                if ($tag === 'a' && $name === 'href'
                        && flibusta_safe_href($attr->value)) {
                    continue; // keep this one
                }
                $el->removeAttribute($attr->name);
            }
            if ($tag === 'a' && $el->getAttribute('href') !== '') {
                $el->setAttribute('rel', 'nofollow noopener noreferrer');
                $el->setAttribute('target', '_blank');
            }
        }
    }

    // Serialize the surviving children of <body>.
    $out = '';
    foreach ($body->childNodes as $child) {
        $out .= $doc->saveHTML($child);
    }
    return trim($out);
}

/** True if href is a safe http/https/mailto URL or a safe relative URL. */
function flibusta_safe_href(string $href): bool {
    $href = trim($href);
    if ($href === '' || strpbrk($href, "\r\n\t") !== false) {
        return false;
    }
    // Reject scheme-relative //host and control-char tricks.
    if (substr($href, 0, 2) === '//') {
        return false;
    }
    $scheme = strtolower((string)parse_url($href, PHP_URL_SCHEME));
    if ($scheme === '') {
        return true; // relative path/fragment
    }
    return in_array($scheme, ['http', 'https', 'mailto'], true);
}

} // function_exists guard
