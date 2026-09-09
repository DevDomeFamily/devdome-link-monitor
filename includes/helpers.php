<?php
/**
 * Shared utilities: the cached hub summary + refresh, the link-health score,
 * URL/path normalization, internal/external host checks, status labels, the daily
 * 404-log purge sweep, the scheduled-rescan dispatcher and the new-broken-links email.
 */

defined('ABSPATH') || exit;

/**
 * The single CACHED snapshot the hub tiles / health / dashboard read.
 * Reads only the settings store (one cached SELECT per request) — never the big tables.
 * Every key is always set.
 */
function devdlink_hub_summary()
{
    return array(
        'last_scan_id'   => devdlink_get_int('last_scan_id', 0),
        'last_scan_at'   => devdlink_get_int('last_scan_at', 0),
        'links_total'    => devdlink_get_int('links_total', 0),
        'links_ok'       => devdlink_get_int('links_ok', 0),
        'links_broken'   => devdlink_get_int('links_broken', 0),
        'links_redirect' => devdlink_get_int('links_redirect', 0),
        'links_timeout'  => devdlink_get_int('links_timeout', 0),
        'links_blocked'  => devdlink_get_int('links_blocked', 0),
        'health_score'   => (float) devdlink_get_setting('health_score', 100),
        'fof_paths'      => devdlink_get_int('fof_paths', 0),
        'fof_human_hits' => devdlink_get_int('fof_human_hits', 0),
        'fof_bot_hits'   => devdlink_get_int('fof_bot_hits', 0),
        'fof_ignored'    => devdlink_get_int('fof_ignored', 0),
        'fof_hot_paths'  => devdlink_get_int('fof_hot_paths', 0),
    );
}

/**
 * Recount from the links table (last completed full scan) + the 404 log and persist
 * the cached summary settings. The ONLY writer of the summary keys.
 * Hooked to the daily devdlink_summary_refresh cron; also called after scans/sweeps.
 */
function devdlink_refresh_summary()
{
    global $wpdb;
    $links = $wpdb->prefix . 'devdlink_links';
    $scans = $wpdb->prefix . 'devdlink_scans';
    $fof   = $wpdb->prefix . 'devdlink_404s';

    devdlink_db_reset_error();
    // Link side: recount from the last completed FULL scan (a recheck reuses its link set).
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL; summary recount is uncacheable by design.
    $scan_id = (int) $wpdb->get_var("SELECT id FROM {$scans} WHERE status = 'completed' AND scan_type = 'full' ORDER BY id DESC LIMIT 1");
    if (devdlink_db_failed()) { return false; } // a failed read is not a count of 0

    $counts = array('total' => 0, 'ok' => 0, 'broken' => 0, 'redirect' => 0, 'timeout' => 0, 'blocked' => 0);
    if ($scan_id) {
        devdlink_db_reset_error();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; scan_id bound via prepare; summary recount is uncacheable by design.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n FROM {$links}
             WHERE scan_id = %d AND status NOT IN ('pending', 'suspect')
             GROUP BY status",
            $scan_id
        ), ARRAY_A);
        if (devdlink_db_failed()) { return false; } // a failed read is not a count of 0
        foreach ((array) $rows as $r) {
            $n = (int) $r['n'];
            $counts['total'] += $n;
            if (array_key_exists($r['status'], $counts)) {
                $counts[$r['status']] = $n;
            }
        }
    }

    // 404 side: LANE B owns the canonical counts SQL; fall back to a direct count when the
    // admin-only file is not loaded (cron requests).
    if (function_exists('devdlink_404_counts')) {
        devdlink_db_reset_error();
        $fc = devdlink_404_counts();
        if (devdlink_db_failed()) { return false; } // a failed read is not a count of 0
    } else {
        devdlink_db_reset_error();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL; summary recount is uncacheable by design.
        $row = $wpdb->get_row(
            "SELECT
                COALESCE(SUM(IF(ignored = 0, 1, 0)), 0) AS paths,
                COALESCE(SUM(IF(ignored = 0, human_hits, 0)), 0) AS human,
                COALESCE(SUM(IF(ignored = 0, bot_hits, 0)), 0) AS bot,
                COALESCE(SUM(IF(ignored = 1, 1, 0)), 0) AS ignored
             FROM {$fof}",
            ARRAY_A
        );
        if (devdlink_db_failed()) { return false; } // a failed read is not a count of 0
        $fc = array(
            'paths'   => $row ? (int) $row['paths'] : 0,
            'human'   => $row ? (int) $row['human'] : 0,
            'bot'     => $row ? (int) $row['bot'] : 0,
            'ignored' => $row ? (int) $row['ignored'] : 0,
        );
    }

    devdlink_db_reset_error();
    // Hot 404 paths: hit 10+ times by user agents that did not match a known crawler — feeds
    // the hub health issue. This is a user-agent match, not proof of a real visitor.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL; summary recount is uncacheable by design.
    $hot = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fof} WHERE ignored = 0 AND human_hits >= 10");
    if (devdlink_db_failed()) { return false; } // a failed read is not a count of 0

    $score = devdlink_health_score($counts['total'], $counts['broken'], $counts['redirect']);
    $ok = true;

    $ok = devdlink_update_setting('links_total', $counts['total']) && $ok;
    $ok = devdlink_update_setting('links_ok', $counts['ok']) && $ok;
    $ok = devdlink_update_setting('links_broken', $counts['broken']) && $ok;
    $ok = devdlink_update_setting('links_redirect', $counts['redirect']) && $ok;
    $ok = devdlink_update_setting('links_timeout', $counts['timeout']) && $ok;
    $ok = devdlink_update_setting('links_blocked', $counts['blocked']) && $ok;
    $ok = devdlink_update_setting('health_score', $score) && $ok;
    $ok = devdlink_update_setting('fof_paths', (int) $fc['paths']) && $ok;
    $ok = devdlink_update_setting('fof_human_hits', (int) $fc['human']) && $ok;
    $ok = devdlink_update_setting('fof_bot_hits', (int) $fc['bot']) && $ok;
    $ok = devdlink_update_setting('fof_ignored', (int) $fc['ignored']) && $ok;
    $ok = devdlink_update_setting('fof_hot_paths', $hot) && $ok;

    if (!$ok) {
        return false; // a summary that could not be fully written is not a summary (finalize stays finalizing and retries)
    }
    return devdlink_hub_summary();
}
add_action('devdlink_summary_refresh', 'devdlink_refresh_summary');

/**
 * Link-health score, 0..100, one decimal. Weighted broken share:
 * broken counts 1.0 each, redirects 0.15 each, timeouts 0 (never punished).
 * With no args it scores the cached summary counts.
 */
function devdlink_health_score($total = null, $broken = null, $redirects = null)
{
    if ($total === null) {
        $total = devdlink_get_int('links_total', 0);
    }
    if ($broken === null) {
        $broken = devdlink_get_int('links_broken', 0);
    }
    if ($redirects === null) {
        $redirects = devdlink_get_int('links_redirect', 0);
    }
    $weighted = (float) $broken + 0.15 * (float) $redirects;
    $score = 100 - round(100 * $weighted / max(1, (int) $total), 1);
    return (float) max(0, min(100, $score));
}

/**
 * Truncate to a byte budget without ever splitting a UTF-8 sequence (the raw substr() the
 * audit found could cut a multibyte character in half and store an invalid byte, which then
 * has to be rendered somewhere).
 *
 * @param string $value
 * @param int    $max   maximum length in BYTES (the column widths are byte budgets)
 * @return string
 */
function devdlink_truncate($value, $max)
{
    $value = (string) $value;
    $max = max(0, (int) $max);
    if (strlen($value) <= $max) {
        return $value;
    }
    if (function_exists('mb_strcut')) {
        return mb_strcut($value, 0, $max, 'UTF-8');
    }
    // Fallback: cut at the byte limit, then drop bytes until the result is valid UTF-8 again
    // (at most three, a sequence is four bytes). A character that ends exactly at the limit
    // is kept - the old lead/continuation walk dropped it.
    $out = substr($value, 0, $max);
    while ($out !== '' && !preg_match('//u', $out)) {
        $out = substr($out, 0, -1);
    }
    return $out;
}

/**
 * Strip control characters and mask the values of query/path parameters that commonly carry
 * credentials, tokens or personal data, plus any bare email address. Applied before a
 * referrer, a user agent or a URL is persisted, displayed, exported or emailed.
 *
 * @param string $value
 * @return string
 */
function devdlink_redact_secrets($value)
{
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $value);
    $value = preg_replace(
        '~\b(pass|password|passwd|pwd|secret|token|auth|authorization|apikey|api_key|api-key|key|sig|signature|session|sessionid|sid|access_token|refresh_token|otp|code|email|e-mail|mail|user|username|login|phone|tel)\b\s*=\s*[^&\s#]*~i',
        '$1=[redacted]',
        (string) $value
    );
    $value = preg_replace('~[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}~', '[redacted-email]', (string) $value);
    return (string) $value;
}

/**
 * The referrer form the 404 log is allowed to persist. Query strings and fragments are
 * ALWAYS discarded (the audit found a stored referrer carrying `email=...&token=...`); the
 * referrer_mode setting only chooses how much of what is left survives.
 *
 *   origin_path (default) — scheme://host[:port]/path
 *   origin                — scheme://host[:port]
 *   none                  — nothing is stored
 *
 * @param string $referrer raw Referer header
 * @return string
 */
function devdlink_referrer_for_log($referrer)
{
    $mode = (string) devdlink_get_setting('referrer_mode', 'origin_path');
    if (!in_array($mode, array('origin_path', 'origin', 'none'), true)) {
        $mode = 'origin_path';
    }
    if ($mode === 'none') {
        return '';
    }

    $referrer = trim((string) $referrer);
    if ($referrer === '') {
        return '';
    }
    $parts = wp_parse_url($referrer);
    if (!is_array($parts) || empty($parts['host'])) {
        return '';
    }
    $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'http';
    if ($scheme !== 'http' && $scheme !== 'https') {
        return '';
    }
    $out = $scheme . '://' . strtolower((string) $parts['host']);
    if (!empty($parts['port'])) {
        $out .= ':' . (int) $parts['port'];
    }
    if ($mode === 'origin_path' && isset($parts['path']) && $parts['path'] !== '') {
        $out .= (string) $parts['path'];
    }
    return devdlink_truncate(devdlink_redact_secrets($out), 300);
}

/**
 * Normalize a request path for the 404 log: strip query + fragment, urldecode once,
 * collapse duplicate slashes, ensure a leading slash, cap at 191 bytes (the column width).
 */
/**
 * Full normalized path (not truncated): query/fragment dropped, percent-decoded EXCEPT the
 * reserved characters whose decoding would change the path's meaning (%2F "/", %3F "?",
 * %23 "#", %25 "%"), control bytes removed, slashes collapsed, leading slash guaranteed.
 */
function devdlink_normalize_path_full($path)
{
    $path = (string) $path;
    $q = strpos($path, '?');
    if ($q !== false) {
        $path = substr($path, 0, $q);
    }
    $f = strpos($path, '#');
    if ($f !== false) {
        $path = substr($path, 0, $f);
    }
    // Keep reserved sequences encoded: /a%2Fb is not /a/b.
    $path = preg_replace_callback('/%(2F|3F|23|25)/i', function ($m) { return '%' . strtoupper($m[1]); }, $path);
    $path = preg_replace_callback('/%(?!2F|3F|23|25)[0-9A-Fa-f]{2}/', function ($m) { return rawurldecode($m[0]); }, $path);
    // Strip control bytes the decode can reintroduce (%0A/%0D...) — a stored newline would
    // otherwise inject an extra line into the .htaccess redirect suggestion and the CSV export.
    $path = preg_replace('/[\x00-\x1F\x7F]/', '', $path);
    // A decoded backslash is a slash to the browser's URL parser ("/\evil.com/x" resolves to
    // https://evil.com/x): keep it as data by re-encoding it.
    $path = str_replace('\\', '%5C', $path);
    $path = preg_replace('~/{2,}~', '/', $path);
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return $path;
}

/** Maximum stored bytes of a 404 path (the identity hash and the stored path cover the same string). */
function devdlink_path_max_bytes()
{
    return 4096;
}

/**
 * Storage form of a 404 path: the full normalized path, byte-safely bounded to 4 KB. Two long
 * paths that differ after byte 191 are two rows AND display as two different paths; the
 * redirect suggestion and the .htaccess rule use exactly the path that was requested.
 */
function devdlink_normalize_path($path)
{
    return devdlink_truncate(devdlink_normalize_path_full($path), devdlink_path_max_bytes());
}

/** Identity of a 404 path: sha256 of the FULL normalized path, so long paths never merge. */
function devdlink_path_hash($full_path)
{
    return hash('sha256', (string) $full_path);
}

/** A path as it must appear in an Apache `Redirect` directive: every segment percent-encoded. */
function devdlink_htaccess_path($path)
{
    $path = (string) $path;
    $parts = explode('/', $path);
    foreach ($parts as $i => $seg) {
        // Encode the already-decoded segment; keep reserved sequences we preserved as-is.
        $parts[$i] = str_replace(array('%252F', '%253F', '%2523', '%2525'), array('%2F', '%3F', '%23', '%25'), rawurlencode($seg));
    }
    return implode('/', $parts);
}

/**
 * The document a relative link in a post must resolve against: the post's own permalink
 * (falling back to the site home). Relative references in `/parent/child/` content resolve
 * against that directory, exactly like a browser — the audited code resolved every relative
 * link against the site root, which silently rewrote them to the wrong URL.
 *
 * @param int $post_id
 * @return string absolute base URL
 */
function devdlink_document_base($post_id = 0)
{
    $post_id = (int) $post_id;
    if ($post_id > 0 && function_exists('get_permalink')) {
        $link = get_permalink($post_id);
        if (is_string($link) && $link !== '' && preg_match('~^https?://~i', $link)) {
            return $link;
        }
    }
    return trailingslashit(home_url());
}

/** RFC 3986 §5.2.4 remove_dot_segments. */
function devdlink_remove_dot_segments($path)
{
    $out = array();
    foreach (explode('/', (string) $path) as $i => $segment) {
        if ($segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($out);
            continue;
        }
        if ($segment === '' && $i > 0) {
            // keep a trailing empty segment (directory URL) but collapse doubles
            $out[] = '';
            continue;
        }
        $out[] = $segment;
    }
    $path = implode('/', $out);
    $path = preg_replace('~/{2,}~', '/', $path);
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . ltrim($path, '/');
    }
    return $path;
}

/**
 * Resolve a relative reference against an absolute base URL with browser semantics
 * (RFC 3986 §5.3): "/x" against the authority, "x" against the base directory,
 * "?x" against the base path, "." / ".." walked.
 *
 * @param string $rel
 * @param string $base absolute http(s) URL
 * @return string absolute URL, or '' when the base is unusable
 */
function devdlink_resolve_relative($rel, $base)
{
    $b = wp_parse_url((string) $base);
    if (!is_array($b) || empty($b['host'])) {
        return '';
    }
    $scheme = isset($b['scheme']) ? strtolower((string) $b['scheme']) : 'https';
    $authority = (string) $b['host'] . (!empty($b['port']) ? ':' . (int) $b['port'] : '');
    $base_path = (isset($b['path']) && $b['path'] !== '') ? (string) $b['path'] : '/';

    $rel = (string) $rel;
    $query = '';
    $q = strpos($rel, '?');
    if ($q !== false) {
        $query = substr($rel, $q);
        $rel = substr($rel, 0, $q);
    }

    if ($rel === '') {
        $path = $base_path;
    } elseif ($rel[0] === '/') {
        $path = $rel;
    } else {
        $cut = strrpos($base_path, '/');
        $dir = ($cut === false) ? '/' : substr($base_path, 0, $cut + 1);
        $path = $dir . $rel;
    }

    return $scheme . '://' . $authority . devdlink_remove_dot_segments($path) . $query;
}

/**
 * Normalize an extracted URL: trim + entity-decode, resolve protocol-relative against the
 * DOCUMENT's scheme (never silently forced to https), resolve root-relative and relative
 * URLs against the source post's permalink, strip the fragment.
 * Returns '' for anything that is not http(s) (mailto/tel/javascript/data/#anchor).
 *
 * @param string $url          raw attribute value from post_content
 * @param int    $base_post_id the post the markup came from (0 = site home as the base)
 */
function devdlink_normalize_url($url, $base_post_id = 0)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    // Not decoded here: the HTML scanner decodes attribute values exactly once (HTML5 table);
    // a second pass turned "&amp;amp;" into "&" and checked a URL the browser never requests.
    $url = trim($url);
    if ($url === '' || $url[0] === '#') {
        return '';
    }

    $base = devdlink_document_base((int) $base_post_id);

    if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $url, $m)) {
        $scheme = strtolower($m[1]);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }
    } elseif (strpos($url, '//') === 0) {
        // Protocol-relative: inherit the scheme of the document it was found in.
        $bp = wp_parse_url($base);
        $url = (isset($bp['scheme']) ? strtolower((string) $bp['scheme']) : 'https') . ':' . $url;
    } else {
        $url = devdlink_resolve_relative($url, $base);
        if ($url === '') {
            return '';
        }
    }

    $f = strpos($url, '#');
    if ($f !== false) {
        $url = substr($url, 0, $f);
    }
    return $url;
}

/**
 * Stable hash of a normalized URL (the links-table dedupe key). SHA-256, not MD5: the key
 * decides whether two links are "the same" for the new-broken diff and for the edit
 * uniqueness check, so a chosen-prefix collision must not be able to merge two URLs.
 * The full canonical URL is stored alongside and compared on every identity-critical read.
 */
function devdlink_url_hash($url)
{
    return hash('sha256', (string) $url);
}

/** Lowercased host of a URL, '' when unparsable. */
function devdlink_url_host($url)
{
    $h = wp_parse_url((string) $url, PHP_URL_HOST);
    return is_string($h) ? strtolower($h) : '';
}

/** Does this URL point at this site? (host equals home_url host, www-insensitive) */
function devdlink_is_internal($url)
{
    $host = preg_replace('~^www\.~', '', devdlink_url_host($url));
    $home = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
    $home = preg_replace('~^www\.~', '', $home);
    return $host !== '' && $host === $home;
}

/** Public post types the scanner walks (attachments excluded). */
/**
 * $wpdb returns the same empty result for "no rows" and "the query failed"; only last_error
 * tells them apart. Reset it before a read that decides queue progress, check it after.
 */
function devdlink_db_reset_error()
{
    global $wpdb;
    if (isset($wpdb)) {
        $wpdb->last_error = '';
    }
}
function devdlink_db_failed()
{
    global $wpdb;
    return isset($wpdb->last_error) && (string) $wpdb->last_error !== '';
}
/** Did the last settings-table load fail? (set by devdlink_get_setting) */
function devdlink_settings_read_failed()
{
    return !empty($GLOBALS['devdlink_settings_read_failed']);
}

function devdlink_public_post_types()
{
    $types = get_post_types(array('public' => true), 'names');
    unset($types['attachment']);
    return array_values($types);
}

/**
 * The evidence classes a check can produce. Stored per link row so the UI can explain WHY a
 * link is in its state instead of collapsing "the name did not resolve", "the certificate is
 * invalid" and "the server timed out" into one word.
 *
 * @return string[]
 */
function devdlink_error_classes()
{
    return array(
        'none',      // nothing to report (2xx / redirect)
        'gone',      // 404 / 410 — the strongest broken evidence
        'http_4xx',  // some other client error
        'http_5xx',  // server error, transient until proven otherwise
        'auth',      // 401 — access restricted, NOT broken
        'ratelimit', // 429 — rate limited, NOT broken
        'antibot',   // 403 after a GET, or 999
        'transport', // no safe transport for this target (no cURL, or an HTTP proxy): not verified
        'dns',       // the name did not resolve
        'tls',       // certificate / TLS failure
        'connect',   // connection refused / reset
        'timeout',   // the request ran out of time
        'protocol',  // malformed response, bad redirect
        'policy',    // our own outbound policy refused the target (never a broken claim)
    );
}

/** Human label for an evidence class ('' when there is nothing useful to add). */
function devdlink_error_class_label($class)
{
    $map = array(
        'gone'      => __('Not found (404/410)', 'devdome-link-monitor'),
        'http_4xx'  => __('Client error', 'devdome-link-monitor'),
        'http_5xx'  => __('Server error', 'devdome-link-monitor'),
        'auth'      => __('Access restricted (401)', 'devdome-link-monitor'),
        'ratelimit' => __('Rate limited (429)', 'devdome-link-monitor'),
        'antibot'   => __('Bot protection', 'devdome-link-monitor'),
        'transport' => __('Not verified: no safe transport for this target (cURL missing, or an HTTP proxy)', 'devdome-link-monitor'),
        'dns'       => __('Host did not resolve', 'devdome-link-monitor'),
        'tls'       => __('TLS/certificate error', 'devdome-link-monitor'),
        'connect'   => __('Connection failed', 'devdome-link-monitor'),
        'timeout'   => __('Timed out', 'devdome-link-monitor'),
        'protocol'  => __('Invalid response', 'devdome-link-monitor'),
        'policy'    => __('Not requested (outbound policy)', 'devdome-link-monitor'),
    );
    return isset($map[$class]) ? $map[$class] : '';
}

/**
 * Human label for a link status, refined by its evidence class.
 * 'timeout' is the UNVERIFIED bucket: the check could not reach a verdict, and it is never
 * presented as a broken link.
 *
 * @param string $status
 * @param string $class optional evidence class
 * @return string
 */
function devdlink_status_label($status, $class = '')
{
    $map = array(
        'pending'   => __('Queued', 'devdome-link-monitor'),
        'suspect'   => __('Re-checking', 'devdome-link-monitor'),
        'ok'        => __('OK', 'devdome-link-monitor'),
        'broken'    => __('Broken', 'devdome-link-monitor'),
        'redirect'  => __('Redirect', 'devdome-link-monitor'),
        'timeout'   => __('Unverified - not counted as broken', 'devdome-link-monitor'),
        'blocked'   => __('Could not verify', 'devdome-link-monitor'),
        'dismissed' => __('Dismissed', 'devdome-link-monitor'),
    );
    $label = isset($map[$status]) ? $map[$status] : ucfirst((string) $status);
    $detail = devdlink_error_class_label((string) $class);
    if ($detail !== '' && in_array((string) $status, array('broken', 'timeout', 'blocked', 'suspect'), true)) {
        $label = $detail . ' - ' . $label;
    }
    return $label;
}

/** Hard ceiling on IGNORED 404 rows (they are exempt from the normal retention window). */
function devdlink_ignored_cap()
{
    return max(50, min(5000, devdlink_get_int('fof_ignored_max_rows', 500)));
}

/** Retention window, in days, for IGNORED 404 rows (their last_seen is frozen). */
function devdlink_ignored_days()
{
    return max(30, min(730, devdlink_get_int('fof_ignored_max_days', 365)));
}

/**
 * Daily 404-log sweep: delete rows older than the retention window, then trim the table
 * to the newest fof_max_rows (oldest last_seen go first). Refreshes the cached summary.
 *
 * Ignored rows keep their own bounded policy — the audit found them exempt from BOTH
 * retention and cap eviction, so an attacker could mint ignored rows for ever.
 */
function devdlink_purge_404s()
{
    global $wpdb;
    $fof = $wpdb->prefix . 'devdlink_404s';

    $days = devdlink_get_int('purge_days', 90);
    if (!in_array($days, array(30, 90, 180), true)) {
        $days = 90;
    }
    // Active rows: the chosen retention window.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; interval bound via prepare; retention sweep is a direct delete by design.
    $wpdb->query($wpdb->prepare("DELETE FROM {$fof} WHERE ignored = 0 AND last_seen < DATE_SUB(NOW(), INTERVAL %d DAY)", $days));

    // Ignored rows: a longer, documented window of their own — never unbounded.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; interval bound via prepare.
    $wpdb->query($wpdb->prepare("DELETE FROM {$fof} WHERE ignored = 1 AND last_seen < DATE_SUB(NOW(), INTERVAL %d DAY)", devdlink_ignored_days()));

    // ...and their own row ceiling (oldest first), so "ignore everything" cannot grow the table.
    $ignored_cap = devdlink_ignored_cap();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL.
    $ignored_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fof} WHERE ignored = 1");
    if ($ignored_count > $ignored_cap) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; limit bound via prepare.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$fof} WHERE id IN (
                SELECT id FROM (SELECT id FROM {$fof} WHERE ignored = 1 ORDER BY last_seen ASC, id ASC LIMIT %d) x
            )",
            $ignored_count - $ignored_cap
        ));
    }

    $cap = max(100, devdlink_get_int('fof_max_rows', 5000));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL; row-cap check on a sweep.
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fof}");
    if ($count > $cap) {
        // Ignored rows go LAST when trimming to the hard cap.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; limit bound via prepare; trims the oldest rows beyond the hard cap.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$fof} WHERE id IN (
                SELECT id FROM (SELECT id FROM {$fof} ORDER BY ignored ASC, last_seen ASC, id ASC LIMIT %d) x
            )",
            $count - $cap
        ));
    }

    devdlink_refresh_summary();
}
add_action('devdlink_purge_sweep', 'devdlink_purge_404s');

/**
 * Daily cron: kick a background scan on the chosen weekly/monthly cadence.
 * No-ops unless scheduled_rescan says one is due and no job is active.
 */
function devdlink_scheduled_scan_dispatch()
{
    $cadence = (string) devdlink_get_setting('scheduled_rescan', 'off');
    if ($cadence !== 'weekly' && $cadence !== 'monthly') {
        return;
    }
    $last = devdlink_get_int('last_scan_at', 0);
    $interval = $cadence === 'weekly' ? WEEK_IN_SECONDS : 30 * DAY_IN_SECONDS;
    if (time() - $last < $interval) {
        return;
    }
    // Don't stomp a genuinely alive job — but a 'running' job with no heartbeat for over 10
    // minutes is dead (crashed tick, power loss); fall through and let devdlink_start_job's
    // self-heal clear it, otherwise scheduled rescans would silently never fire again.
    $job = function_exists('devdlink_get_job') ? devdlink_get_job() : null;
    if ($job && is_array($job) && isset($job['status'])) {
        if ($job['status'] === 'paused') {
            return;
        }
        $age = time() - (int) (isset($job['updated_at']) ? $job['updated_at'] : 0);
        if ($job['status'] === 'running' && $age <= 600) {
            return;
        }
    }
    if (function_exists('devdlink_start_job')) {
        devdlink_start_job('scan');
    }
}
add_action('devdlink_scheduled_scan', 'devdlink_scheduled_scan_dispatch');

/**
 * The suite hub's Connect action (admin-post, nonce-checked) with a return trip to the given
 * tab of this screen. rawurlencode() is deliberate: add_query_arg() does not encode values,
 * and the return URL carries its own "&lm_tab=" which would otherwise be split off.
 */
function devdlink_connect_url($tab = 'overview')
{
    return add_query_arg(
        array(
            'action'   => 'devdcorev1_connect_go',
            '_wpnonce' => wp_create_nonce('devdcorev1_connect_go'),
            'return'   => rawurlencode(admin_url('admin.php?page=' . DEVDLINK_PAGE . '&lm_tab=' . sanitize_key($tab))),
        ),
        admin_url('admin-post.php')
    );
}

/**
 * Report a completed full scan to DevDome. Only for a site connected to a DevDome account
 * (the suite token identifies it); DevDome keeps the latest summary for the dashboard and
 * emails the ACCOUNT address the branded "new broken links" summary when there are any and
 * the email setting is on. Returns the decoded response, null when not connected, WP_Error
 * when DevDome could not be reached.
 */
function devdlink_cloud_report($scan_id, $new_broken_count = 0)
{
    if (!function_exists('devdcorev1_connection_state')) {
        return null;
    }
    $conn = devdcorev1_connection_state();
    if (empty($conn['ok'])) {
        return null;
    }
    $site  = (string) get_option('devdcorev1_site_id', '');
    $token = (string) get_option('devdcorev1_site_token', '');
    if ($site === '' || $token === '') {
        return null;
    }
    $body = devdlink_cloud_report_payload($site, (int) $scan_id, (int) $new_broken_count);
    $resp = wp_remote_post('https://analytics.devdome.com/api/plugin/links', array(
        'timeout' => 15,
        // The site token authenticates in a request header (request bodies get logged; headers
        // are stripped), exactly as the readme and the privacy-policy text say.
        'headers' => array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ),
        'body'    => wp_json_encode($body),
    ));
    if (is_wp_error($resp)) {
        return $resp;
    }
    $data = json_decode(wp_remote_retrieve_body($resp), true);
    if (200 !== (int) wp_remote_retrieve_response_code($resp) || !is_array($data)) {
        return new WP_Error('devdlink_cloud', 'DevDome did not accept the scan report.');
    }
    return $data;
}

/**
 * What a connected site sends DevDome after a full scan: COUNTS ONLY (totals, health score,
 * how many links are newly broken), the email switch and the address of this screen. Never a
 * URL, an anchor text or a post title - those stay in this database (readme: External services).
 */
function devdlink_cloud_report_payload($site, $scan_id, $new_broken_count)
{
    $s = devdlink_hub_summary();
    return array(
        'site_id'          => (string) $site,
        'site_domain'      => (string) $site,
        'scan_id'          => (int) $scan_id,
        'links_total'      => (int) $s['links_total'],
        'links_ok'         => (int) $s['links_ok'],
        'links_broken'     => (int) $s['links_broken'],
        'links_redirect'   => (int) $s['links_redirect'],
        'health_score'     => (float) $s['health_score'],
        'new_broken_count' => max(0, (int) $new_broken_count),
        'email'            => (bool) devdlink_get_int('email_new_broken', 0),
        'admin_url'        => admin_url('admin.php?page=' . DEVDLINK_PAGE . '&lm_tab=links'),
    );
}
