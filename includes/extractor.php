<?php
/**
 * Link extractor — chunked, resumable walk over published content.
 *
 * Each extract tick pulls one ID-bounded chunk of published posts (public post types),
 * scans post_content for <a href> and <img src> with the shared HTML tokenizer, normalizes every URL against the
 * source post's permalink, skips excluded hosts and non-http(s) schemes, and upserts unique
 * URLs into the links table (INSERT IGNORE on the (scan_id, url_hash) unique key) plus a
 * source mapping row per (link, post). No DOM extension dependency — a small tokenizer over the
 * stored markup, exactly what the editor saved.
 *
 * SCANNED:     post_content of published posts in public post types (attachments excluded) —
 *              <a href> and <img src> in the rendered markup (comments are skipped).
 * NOT SCANNED: widgets, menus, theme/customizer options, post meta and custom fields,
 *              srcset candidates, oEmbed/iframe targets, reusable/synced pattern bodies that
 *              are not inlined in the post, drafts, private and scheduled content.
 * Both lists are published in readme.txt — the scanner must never be described as covering
 * the whole site.
 */

defined('ABSPATH') || exit;

/**
 * Extractor/normalizer contract version. Bumped whenever URL normalization, the source
 * coverage or the identity hash changes, so a stored scan can be told apart from one built by
 * a different contract and rebuilt. Recorded on every scans row.
 */
define('DEVDLINK_EXTRACTOR_VERSION', 2);

/** Count of published posts across public post types (for progress/ETA). */
function devdlink_total_posts()
{
    global $wpdb;
    $types = array_values((array) devdlink_public_post_types());
    if (!$types) {
        return 0;
    }
    $placeholders = implode(', ', array_fill(0, count($types), '%s'));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- single count over core posts; placeholders generated, values bound via prepare.
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per post type, generated above.
        $types
    ));
}

/**
 * Extract links from one chunk of published posts.
 *
 * @param int $scan_id
 * @param int $cursor last post ID processed (ascending walk)
 * @param int $chunk  posts this tick
 * @return array{last_id:int, processed:int, done:bool}
 */
function devdlink_extract_chunk($scan_id, $cursor, $chunk)
{
    global $wpdb;
    $scan_id = (int) $scan_id;
    $cursor = (int) $cursor;
    $chunk = max(10, min(500, (int) $chunk));

    $types = array_values((array) devdlink_public_post_types());
    if (!$types) {
        return array('last_id' => $cursor, 'processed' => 0, 'done' => true);
    }
    $placeholders = implode(', ', array_fill(0, count($types), '%s'));
        $params = array_merge($types, array($cursor, $chunk));
    devdlink_db_reset_error();


    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked ID-bounded walk over core posts; placeholders generated, values bound via prepare.
    // phpcs:disable WordPress.DB.PreparedSQLPlaceholders -- one %s per post type is generated above; the sniff cannot count them.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, post_content FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type IN ($placeholders) AND ID > %d
         ORDER BY ID ASC LIMIT %d",
        $params
    ));
    // phpcs:enable WordPress.DB.PreparedSQLPlaceholders

        if (devdlink_db_failed()) {
        // A failed read is NOT an empty result: the phase stays where it is and the next tick
        // re-reads from the same cursor.
        return array('last_id' => $cursor, 'processed' => 0, 'done' => false, 'error' => 'db_read');
    }
    if (!$rows) {
        return array('last_id' => $cursor, 'processed' => 0, 'done' => true);
    }
    $last_id = $cursor;
    $processed = 0;
    foreach ($rows as $r) {
        $content = (string) $r->post_content;
        if ($content !== '') {
            foreach (devdlink_extract_from_html($content) as $f) {
                if (devdlink_store_link($scan_id, $f['url'], $f['type'], $f['anchor'], (int) $r->ID) === -1) {
                    // A write for this post failed: resume from the last fully stored post.
                    return array('last_id' => $last_id, 'processed' => $processed, 'done' => false, 'error' => 'db_write');
                }
            }
        }
        $last_id = (int) $r->ID;
        $processed++;
    }
    return array('last_id' => $last_id, 'processed' => $processed, 'done' => false);
}

/**
 * Pull raw link candidates out of an HTML fragment with the shared quote-aware scanner
 * (includes/html.php): comments, <script>/<style> and CDATA are skipped, a ">" inside a
 * quoted attribute cannot end a tag, and quoted or bare attribute values are read alike.
 * Edit/Unlink and the post-save verification use the very same scanner.
 *
 * @param string $html
 * @return array[] each array('url' => raw string, 'type' => 'href'|'img', 'anchor' => string)
 */
function devdlink_extract_from_html($html)
{
    $out = array();
    if (!is_string($html) || $html === '') {
        return $out;
    }
    foreach (devdlink_html_tags($html, array('a', 'img')) as $t) {
        if ($t['name'] === 'a') {
            $url = isset($t['attrs']['href']) ? trim((string) $t['attrs']['href']) : '';
            if ($url === '') {
                continue;
            }
            $inner  = ($t['inner_start'] !== null) ? substr($html, $t['inner_start'], $t['inner_end'] - $t['inner_start']) : '';
            $anchor = wp_strip_all_tags((string) $inner);
            $anchor = trim(preg_replace('~\s+~u', ' ', $anchor));
            $anchor = devdlink_truncate($anchor, 191);
            $out[] = array('url' => $url, 'type' => 'href', 'anchor' => $anchor);
        } else {
            $url = isset($t['attrs']['src']) ? trim((string) $t['attrs']['src']) : '';
            if ($url === '') {
                continue;
            }
            $out[] = array('url' => $url, 'type' => 'img', 'anchor' => '');
        }
    }
    return $out;
}

/**
 * Normalize + upsert one URL for a scan and map its source post.
 *
 * @param int    $scan_id
 * @param string $url     raw URL as found in the markup
 * @param string $type    href | img
 * @param string $anchor  anchor text (href links)
 * @param int    $post_id source post
 * @return int link row id, or 0 when skipped (empty/non-http(s)/excluded)
 */
function devdlink_store_link($scan_id, $url, $type, $anchor, $post_id)
{
    global $wpdb;
    $scan_id = (int) $scan_id;
    $post_id = (int) $post_id;

    $normalized = devdlink_normalize_url((string) $url, $post_id);
    if ($normalized === '') {
        return 0;
    }

    $host = strtolower((string) devdlink_url_host($normalized));
    if ($host === '') {
        return 0;
    }
    if (devdlink_extract_host_excluded($host)) {
        return 0;
    }
    $host = devdlink_truncate($host, 191);

    // A URL longer than this is not a link anyone can act on and it would only be truncated
    // into a different URL than the one in the content — refuse it instead of storing a lie.
    if (strlen($normalized) > 2000) {
        return 0;
    }

    $hash = devdlink_url_hash($normalized);
    $type = ($type === 'img') ? 'img' : 'href';
    $anchor = devdlink_truncate(trim((string) $anchor), 191);
    $internal = devdlink_is_internal($normalized) ? 1 : 0;

        $links = $wpdb->prefix . 'devdlink_links';
    devdlink_db_reset_error();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table dedupe upsert (INSERT IGNORE on the (scan_id, url_hash) unique key); values bound via prepare.
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$links}
            (scan_id, url, url_hash, host, is_internal, link_type, anchor_text, status)
         VALUES (%d, %s, %s, %s, %d, %s, %s, 'pending')",
        $scan_id,
        $normalized,
        $hash,
        $host,
        $internal,
        $type,
        $anchor
    ));

        if (devdlink_db_failed()) {
        return -1;
    }
    $link_id = (int) $wpdb->insert_id;
    if (!$link_id) {
        // Duplicate within this scan — fetch the existing row's id for the source mapping.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table id lookup; values bound via prepare.
        $link_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$links} WHERE scan_id = %d AND url_hash = %s",
            $scan_id,
            $hash
        ));
        if (devdlink_db_failed()) {
            return -1;
        }
    }
    if (!$link_id) {
        return 0;
    }

    // Occurrence row per (link, post, usage). A repeat of the same usage in the same post
    // bumps the counter; the first non-empty anchor text is kept.
    $sources = $wpdb->prefix . 'devdlink_link_sources';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal link_sources occurrence upsert on the (link_id, post_id, usage_type) unique key; values bound via prepare.
    $wpdb->query($wpdb->prepare(
        "INSERT INTO {$sources} (link_id, post_id, usage_type, anchor_text, occurrences)
         VALUES (%d, %d, %s, %s, 1)
         ON DUPLICATE KEY UPDATE
             occurrences = LEAST(occurrences + 1, 65535),
             anchor_text = IF(anchor_text IS NULL OR anchor_text = '', VALUES(anchor_text), anchor_text)",
        $link_id,
        $post_id,
        $type,
        $anchor
    ));

        if (devdlink_db_failed()) {
        return -1;
    }
    // The URL row describes the URL, not its first sighting: an anchor usage anywhere makes
    // it editable/unlinkable, and the first non-empty anchor text is kept for display.
    if ($type === 'href') {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links row promotion; values bound via prepare.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$links} SET link_type = 'href', anchor_text = IF(anchor_text = '', %s, anchor_text) WHERE id = %d AND (link_type <> 'href' OR anchor_text = '')",
            $anchor,
            $link_id
        ));
    }

        return devdlink_db_failed() ? -1 : $link_id;
}
/** Excluded hosts from settings, lowercased and cleaned (accepts bare hosts or pasted URLs). */
function devdlink_excluded_hosts()
{
    $raw = devdlink_get_array('excluded_domains');
    $out = array();
    foreach ((array) $raw as $entry) {
        $entry = strtolower(trim((string) $entry));
        if ($entry === '') {
            continue;
        }
        if (strpos($entry, '//') !== false) {
            $parsed = wp_parse_url($entry, PHP_URL_HOST);
            if ($parsed) {
                $entry = strtolower($parsed);
            }
        }
        $entry = rtrim($entry, '/.');
        $entry = preg_replace('~^www\.~', '', $entry);
        if ($entry !== '') {
            $out[] = $entry;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Does a host match the excluded list (www-insensitive, subdomains included)?
 *
 * @param string $host lowercased host
 * @return bool
 */
function devdlink_extract_host_excluded($host)
{
    $host = preg_replace('~^www\.~', '', strtolower((string) $host));
    if ($host === '') {
        return false;
    }
    foreach (devdlink_excluded_hosts() as $excluded) {
        if ($host === $excluded) {
            return true;
        }
        $suffix = '.' . $excluded;
        if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}
