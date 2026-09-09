<?php
/**
 * 404 Monitor — front-end capture (cheap: one early return + one prepared upsert),
 * bot-vs-human classification (vendored devdome-core when loaded, conservative UA
 * fallback otherwise), redirect suggestion helper, Redirect Manager hand-off, and
 * the nonce-gated CSV export of the 404 log.
 */

defined('ABSPATH') || exit;

// devdlink_404_rows() / devdlink_404_counts() live in fourohfour-admin.php, which the
// main file only loads under is_admin(). REST requests and cron (summary refresh) run with
// is_admin() false but still call them — load the file here so they always exist.
require_once __DIR__ . '/fourohfour-admin.php';

/* ---------------------------------------------------------------------------
 * Front-end capture.
 * ------------------------------------------------------------------------- */

/**
 * How many BRAND-NEW 404 paths one request may create. A single request that renders many
 * 404s (or a crafted flood) previously inserted an unbounded number of rows in one pass while
 * the cached row count stayed frozen — the audit turned 99 rows into 149 against a cap of 100.
 */
function devdlink_fof_new_paths_per_request()
{
    return 5;
}

/**
 * Log the current 404. Hooked at template_redirect priority 1.
 *
 * HARD BUDGET: the is_404() check is the only work done on normal page views.
 * On a 404 we run exactly ONE prepared upsert — ignored paths keep their row
 * frozen (the IF(ignored=1, ...) guards) so "never log again" costs nothing extra.
 *
 * CAPACITY: a brand-new path is only inserted after a LIVE count (never the cached one the
 * audit showed frozen at 99 while the table grew to 149), and one request may create at most
 * devdlink_fof_new_paths_per_request() new paths. Existing paths take the UPDATE branch and
 * cost no count at all. Documented tolerance: the table can exceed the cap only by the number
 * of inserts that are genuinely in flight at the same instant; the daily sweep trims back.
 */
function devdlink_log_404()
{
    if (!is_404()) {
        return;
    }

    global $wpdb;

    // FILTER_SANITIZE_URL, not sanitize_text_field(): the latter removes every %XX octet and
    // turns "/enc%20test/a%2Fb" into "/enctest/ab". The URL filter drops control bytes and
    // characters that cannot appear in a URL while keeping the encoding intact; the normalizer
    // below bounds and cleans the rest, and the path is escaped on output.
    $raw  = isset($_SERVER['REQUEST_URI']) ? (string) filter_var(wp_unslash($_SERVER['REQUEST_URI']), FILTER_SANITIZE_URL) : '';
    // The stored path and the identity hash cover the SAME bounded string, so a row can never
    // display a path other than the one it counts.
    $full = devdlink_normalize_path($raw);
    if ('' === $full || '/' === $full) {
        return;
    }
    $path = $full;
    $hash = devdlink_path_hash($full);

    // Bound every stored byte BEFORE any database work, then strip control characters and
    // mask credential-looking parameters. The audit found a stored referrer carrying
    // `email=...&token=...`; the referrer is now reduced to origin+path by policy.
    // Classification always uses the REQUEST user agent; the storage toggle only decides
    // whether that string is persisted. (With classification tied to the stored value,
    // turning storage off silently made every hit a "bot".)
    $request_ua = isset($_SERVER['HTTP_USER_AGENT'])
        ? devdlink_truncate(
            devdlink_redact_secrets(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))),
            255
        )
        : '';
    $ua = devdlink_get_int('store_user_agent', 1) ? $request_ua : '';
    $referrer = isset($_SERVER['HTTP_REFERER'])
        ? devdlink_referrer_for_log(esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])))
        : '';
    $is_bot = ('bot' === devdlink_classify_hit($request_ua));

    $table = $wpdb->prefix . 'devdlink_404s';
    $cap = max(100, devdlink_get_int('fof_max_rows', 5000));

    // Existing path: one atomic counter bump keyed by the full-path hash, never subject to the cap.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single prepared counter bump on the internal prefixed 404 table; all values bound via prepare; write path is not cacheable.
    $bumped = $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET
             human_hits    = human_hits + IF(ignored = 1, 0, %d),
             bot_hits      = bot_hits + IF(ignored = 1, 0, %d),
             last_referrer = IF(ignored = 1, last_referrer, %s),
             last_ua       = IF(ignored = 1, last_ua, %s),
             last_seen     = IF(ignored = 1, last_seen, NOW())
         WHERE path_hash = %s",
        $is_bot ? 0 : 1,
        $is_bot ? 1 : 0,
        $referrer,
        $ua,
        $hash
    ));
    if ($bumped) {
        return;
    }

    if (!isset($GLOBALS['devdlink_fof_new_this_request'])) {
        $GLOBALS['devdlink_fof_new_this_request'] = 0;
    }
    if ($GLOBALS['devdlink_fof_new_this_request'] >= devdlink_fof_new_paths_per_request()) {
        return;
    }
    $GLOBALS['devdlink_fof_new_this_request']++;

    // LIVE count — the correctness boundary is never a cached value. This runs at most
    // devdlink_fof_new_paths_per_request() times per request and only for paths that do
    // not exist yet, so a flood cannot turn it into a load problem either.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL; capacity boundary must not be cached.
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    if ($count >= $cap) {
        return;
    }

    // New path. Two requests can race here: the loser's INSERT turns into the counter bump,
    // so no hit is ever lost (ON DUPLICATE KEY on the path-hash unique key).
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; all values bound via prepare; bounded insert on the front-end 404 path.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$table} (path, path_hash, human_hits, bot_hits, last_referrer, last_ua, first_seen, last_seen)
         VALUES (%s, %s, %d, %d, %s, %s, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
             human_hits    = human_hits + IF(ignored = 1, 0, VALUES(human_hits)),
             bot_hits      = bot_hits + IF(ignored = 1, 0, VALUES(bot_hits)),
             last_referrer = IF(ignored = 1, last_referrer, VALUES(last_referrer)),
             last_ua       = IF(ignored = 1, last_ua, VALUES(last_ua)),
             last_seen     = IF(ignored = 1, last_seen, NOW())",
        $path,
        $hash,
        $is_bot ? 0 : 1,
        $is_bot ? 1 : 0,
        $referrer,
        $ua
    ));
}
add_action('template_redirect', 'devdlink_log_404', 1);

/**
 * Classify a hit from its USER AGENT ONLY.
 *
 * This is a user-agent match, not a bot detector: a user agent is trivially forged, so a
 * "human" row only means "the string did not match a known crawler pattern". The result is
 * labelled that way everywhere it is shown.
 *
 * An EMPTY user agent is decided HERE, before any shared-core detector is consulted, so the
 * answer does not change depending on which DevDome core version happens to be loaded (the
 * audit measured exactly that inconsistency).
 *
 * @param string $ua Raw user-agent string.
 * @return string 'bot'|'human'
 */
function devdlink_classify_hit($ua)
{
    $ua = (string) $ua;
    if ('' === trim($ua)) {
        return 'bot'; // deliberate and version-independent: no UA is not a browser.
    }
    // The shared core is an ADDITIVE signal, never a veto: its pattern feed can be empty
    // (the WordPress.org build never syncs it, a fresh install has not synced yet), and an
    // empty feed matches nothing - so the built-in patterns below always run too.
    if (function_exists('devdcorev1_ua_is_bot') && devdcorev1_ua_is_bot($ua)) {
        return 'bot';
    }
    if (preg_match('~(bot|crawl|spider|slurp|curl|wget|python-|httpclient|headless|scrapy|facebookexternalhit|monitor)~i', $ua)) {
        return 'bot';
    }
    return 'human';
}

/**
 * Which classifier produced the bot/human split, for honest display.
 *
 * @return string
 */
function devdlink_classifier_name()
{
    if (function_exists('devdcorev1_ua_is_bot')) {
        return 'devdome-core' . (defined('DEVDCOREV1_VERSION') ? ' ' . DEVDCOREV1_VERSION : '') . ' + built-in user-agent patterns';
    }
    return 'built-in user-agent patterns';
}

/* ---------------------------------------------------------------------------
 * Redirect suggestion (fuzzy slug match).
 * ------------------------------------------------------------------------- */

/**
 * Suggest a redirect target for a 404 path by fuzzy-matching its last segment
 * against published post/page slugs.
 *
 * Candidates are fetched with a LIKE over the first characters of the segment
 * (LIMIT 50) and ranked by similar_text percentage with a levenshtein tie-break;
 * only matches with >= 60% similarity are accepted.
 *
 * @param string $path Normalized 404 path (e.g. "/old-page/").
 * @return array|null array('url' =>, 'label' =>, 'rm_url' =>, 'htaccess' =>) or null.
 */
function devdlink_suggest_redirect($path)
{
    static $cache = array();

    $path = devdlink_normalize_path($path);
    if ('' === $path || '/' === $path) {
        return null;
    }
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }

    // Last non-empty segment, extension stripped, slug-normalized.
    $segment = basename(untrailingslashit($path));
    $segment = preg_replace('/\.[a-z0-9]{1,5}$/i', '', $segment);
    $segment = sanitize_title($segment);
    if ('' === $segment) {
        $cache[$path] = null;
        return null;
    }

    global $wpdb;

    $types = devdlink_public_post_types();
    $types = array_values(array_filter(array_map('sanitize_key', (array) $types)));
    if (!$types) {
        $types = array('post', 'page');
    }

    $needle       = strlen($segment) >= 3 ? substr($segment, 0, 3) : $segment;
    $placeholders = implode(',', array_fill(0, count($types), '%s'));
    $params       = array_merge($types, array('%' . $wpdb->esc_like($needle) . '%'));

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- candidate slug lookup over core posts table; the IN() list is built from %s placeholders only, all values bound via prepare; literal LIMIT.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_name, post_title FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_name <> '' AND post_type IN ({$placeholders}) AND post_name LIKE %s
         LIMIT 50",
        $params
    ));

    $best_id  = 0;
    $best_pct = 0.0;
    $best_lev = PHP_INT_MAX;
    $best_ttl = '';
    if ($rows) {
        foreach ($rows as $row) {
            $slug = (string) $row->post_name;
            $pct  = 0.0;
            similar_text($segment, $slug, $pct);
            $lev = levenshtein(substr($segment, 0, 255), substr($slug, 0, 255));
            if ($pct > $best_pct || ($pct === $best_pct && $lev < $best_lev)) {
                $best_pct = $pct;
                $best_lev = $lev;
                $best_id  = (int) $row->ID;
                $best_ttl = (string) ('' !== $row->post_title ? $row->post_title : $slug);
            }
        }
    }

    if (!$best_id || $best_pct < 60) {
        $cache[$path] = null;
        return null;
    }

    $url = get_permalink($best_id);
    if (!$url) {
        $cache[$path] = null;
        return null;
    }

    $suggestion = array(
        'url'      => $url,
        'label'    => $best_ttl,
        'rm_url'   => devdlink_rm_active() ? devdlink_rm_prefill_url($path, $url) : '',
        'htaccess' => 'Redirect 301 ' . devdlink_htaccess_path($path) . ' ' . $url,
    );

    $cache[$path] = $suggestion;
    return $suggestion;
}

/** Is the DevDome Redirect Manager plugin active (for the "Create redirect" hand-off)? */
function devdlink_rm_active()
{
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    return is_plugin_active('devdome-redirect-manager/devdome-redirect-manager.php');
}

/**
 * Admin URL of the Redirect Manager screen with source/target prefilled.
 *
 * @param string $path   The 404 path (redirect source).
 * @param string $target The suggested destination URL.
 * @return string
 */
function devdlink_rm_prefill_url($path, $target)
{
    // add_query_arg() does NOT encode values (its inputs are expected to be encoded), so a
    // path such as "/foo&bar=baz" must be encoded here or it corrupts the query string.
    return add_query_arg(
        array(
            'page'      => 'devdome-redirect-manager',
            'lm_source' => rawurlencode($path),
            'lm_target' => rawurlencode($target),
        ),
        admin_url('admin.php')
    );
}

/* ---------------------------------------------------------------------------
 * CSV export (nonce + cap gated download, streams and exits).
 * ------------------------------------------------------------------------- */

/**
 * CSV-safe cell.
 *
 * The audited helper only looked at byte 0, so `=cmd` was defused but ` =cmd`, "\n=cmd",
 * a BOM-prefixed or Unicode-space-prefixed payload was not — Excel, LibreOffice and Google
 * Sheets all trim leading whitespace before deciding whether a cell is a formula. Every
 * leading whitespace/control/BOM byte is removed first, and if what remains starts with a
 * formula trigger the whole cell is prefixed with a single quote.
 *
 * @param string $value Raw cell value.
 * @return string
 */
function devdlink_fof_csv_cell($value)
{
    $v = (string) $value;
    if ('' === $v) {
        return $v;
    }
    // Strip leading ASCII control/space bytes, the UTF-8 BOM and the common Unicode spaces
    // (NBSP U+00A0, the U+2000..U+200B range, U+202F, U+205F, U+3000).
    $v = preg_replace('~^(?:[\x00-\x20]|\xEF\xBB\xBF|\xC2\xA0|\xE2\x80[\x80-\x8B\xAF]|\xE2\x81\x9F|\xE3\x80\x80)+~', '', $v);
    // Control bytes anywhere else would break the row apart in some readers.
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    if ('' === $v) {
        return $v;
    }
    if (in_array($v[0], array('=', '+', '-', '@', "\t", "\r", '|'), true)) {
        $v = "'" . $v;
    }
    return $v;
}

/** Stream the 404 log as CSV. Fires on admin_init when lm_export_404 is present. */
function devdlink_handle_export_404()
{
    if (empty($_GET['lm_export_404']) || !current_user_can(devdlink_capability('data'))) {
        return;
    }
    check_admin_referer('devdlink_export_404', '_lmexp');

    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_404s';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="devdome-404-log-' . gmdate('Y-m-d') . '.csv"');

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV rows to php://output for a nonce + capability gated admin download.
    $out = fopen('php://output', 'w');
    fputcsv($out, array('path', 'human_hits', 'bot_hits', 'first_seen', 'last_seen', 'last_referrer', 'ignored'));

    $last = 0;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked export read over the internal prefixed 404 table; bounds bound via prepare; export must read live rows.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, path, human_hits, bot_hits, first_seen, last_seen, last_referrer, ignored
             FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
            $last,
            500
        ), ARRAY_A);
        if (!$rows) {
            break;
        }
        foreach ($rows as $r) {
            $last = (int) $r['id'];
            // Every text field goes through the same safe-cell policy, and the referrer is
            // redacted again on the way out (rows stored by an older build may predate the
            // origin+path referrer policy).
            fputcsv($out, array(
                devdlink_fof_csv_cell($r['path']),
                (int) $r['human_hits'],
                (int) $r['bot_hits'],
                devdlink_fof_csv_cell((string) $r['first_seen']),
                devdlink_fof_csv_cell((string) $r['last_seen']),
                devdlink_fof_csv_cell(devdlink_redact_secrets((string) $r['last_referrer'])),
                (int) $r['ignored'],
            ));
        }
    } while (count($rows) === 500);

    exit;
}
add_action('admin_init', 'devdlink_handle_export_404');
