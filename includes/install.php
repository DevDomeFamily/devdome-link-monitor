<?php
/**
 * Lifecycle: schema creation, the versioned + resumable upgrade runner, per-site and
 * network activation/deactivation, new-site provisioning and the privacy-policy text.
 *
 * Deactivation is non-destructive (schedules and locks only); full removal is uninstall.php.
 */

defined('ABSPATH') || exit;

/**
 * Schema contract version. Bump on ANY table change and add the matching data migration to
 * devdlink_run_migrations(). Stored per site in the devdlink_schema option (an option,
 * not the settings table, so it is readable before the settings table is known to exist).
 *
 *   1 — 0.1.x: url_hash char(32) (MD5), no error_class, no extractor_version
 *   2 — 0.2.0: url_hash char(64) (SHA-256), links.error_class, scans.extractor_version
 */
define('DEVDLINK_SCHEMA', 6);

/** Create/upgrade every table. Idempotent — dbDelta only issues what is actually missing. */
function devdlink_install_tables()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $p = $wpdb->prefix . 'devdlink_';

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // name/value settings store
    dbDelta("CREATE TABLE {$p}settings (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        setting_name varchar(191) NOT NULL,
        setting_value longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY setting_name (setting_name)
    ) $charset_collate;");

    // TOOL 1: 404 log, one row per normalized path
    // Schema 5 widens 404s.path from varchar(191) to text. MySQL refuses that ALTER while the
    // schema-4 helper index (no key length) still covers the column, so it goes first.
    if ((int) get_option('devdlink_schema', 0) > 0 && (int) get_option('devdlink_schema', 0) < 5) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time index drop before dbDelta widens the column.
        if ($wpdb->get_var("SHOW INDEX FROM {$p}404s WHERE Key_name = 'idx_path'")) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time index drop before dbDelta widens the column.
            $wpdb->query("ALTER TABLE {$p}404s DROP INDEX idx_path");
        }
    }
    dbDelta("CREATE TABLE {$p}404s (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        path text NOT NULL,
        path_hash char(64) NOT NULL DEFAULT '',
        human_hits bigint(20) unsigned NOT NULL DEFAULT 0,
        bot_hits bigint(20) unsigned NOT NULL DEFAULT 0,
        last_referrer text NULL,
        last_ua text NULL,
        ignored tinyint(1) NOT NULL DEFAULT 0,
        first_seen datetime NOT NULL,
        last_seen datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY uq_path_hash (path_hash),
        KEY idx_ignored_seen (ignored, last_seen),
        KEY idx_human (human_hits)
    ) $charset_collate;");

    // TOOL 2: one row per unique URL per scan.
    // url_hash is SHA-256 (64 hex) — the identity key decides whether two links are "the
    // same" for the new-broken diff and the edit uniqueness check, so MD5 is not enough.
    // The full canonical url is stored beside it and compared on identity-critical reads.
    dbDelta("CREATE TABLE {$p}links (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        scan_id bigint(20) unsigned NOT NULL,
        url text NOT NULL,
        url_hash char(64) NOT NULL,
        host varchar(191) NOT NULL DEFAULT '',
        is_internal tinyint(1) NOT NULL DEFAULT 0,
        link_type varchar(10) NOT NULL DEFAULT 'href',
        anchor_text text NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        error_class varchar(16) NOT NULL DEFAULT 'none',
        http_code smallint unsigned NOT NULL DEFAULT 0,
        redirect_url text NULL,
        redirect_hops tinyint unsigned NOT NULL DEFAULT 0,
        fail_count tinyint unsigned NOT NULL DEFAULT 0,
        checked_at datetime NULL,
        error_message text NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_scan_hash (scan_id, url_hash),
        KEY idx_scan_status (scan_id, status),
        KEY idx_host (host),
        KEY idx_scan_checked (scan_id, status, checked_at)
    ) $charset_collate;");

    // which posts contain a link
    // One row per (link, post, usage): an <a href> and an <img src> of the same URL in the
    // same post are two occurrences, each with its own anchor text and count (schema 3).
    dbDelta("CREATE TABLE {$p}link_sources (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        link_id bigint(20) unsigned NOT NULL,
        post_id bigint(20) unsigned NOT NULL,
        usage_type varchar(10) NOT NULL DEFAULT 'href',
        anchor_text varchar(191) NULL,
        occurrences smallint unsigned NOT NULL DEFAULT 1,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_link_post_use (link_id, post_id, usage_type),
        KEY idx_post (post_id)
    ) $charset_collate;");

    // scan sessions
    dbDelta("CREATE TABLE {$p}scans (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        status varchar(32) NOT NULL DEFAULT 'queued',
        scan_type varchar(16) NOT NULL DEFAULT 'full',
        extractor_version smallint unsigned NOT NULL DEFAULT 0,
        started_at datetime NULL,
        finished_at datetime NULL,
        user_id bigint(20) unsigned NOT NULL DEFAULT 0,
        total_posts bigint(20) unsigned NOT NULL DEFAULT 0,
        total_links bigint(20) unsigned NOT NULL DEFAULT 0,
        ok_count bigint(20) unsigned NOT NULL DEFAULT 0,
        broken_count bigint(20) unsigned NOT NULL DEFAULT 0,
        redirect_count bigint(20) unsigned NOT NULL DEFAULT 0,
        timeout_count bigint(20) unsigned NOT NULL DEFAULT 0,
        blocked_count bigint(20) unsigned NOT NULL DEFAULT 0,
        new_broken_count bigint(20) unsigned NOT NULL DEFAULT 0,
        error_message text NULL,
        PRIMARY KEY  (id),
        KEY idx_status (status),
        KEY idx_started (started_at)
    ) $charset_collate;");
}

/** The shipped setting defaults. Seeded only when a name is missing — never overwritten. */
function devdlink_default_settings()
{
    return array(
        // scanner tuning
        'check_timeout'    => 10,      // seconds per HTTP check (clamped 3..30)
        'scan_chunk_size'  => 50,      // posts parsed per extract tick (clamped 10..500)
        'check_batch_size' => 15,      // links checked per check tick (clamped 3..50)
        'strike_gap_seconds' => 15,    // minimum separation between the two failing observations (5s floor)
        'user_agent'       => 'Mozilla/5.0 (compatible; DevDomeLinkMonitor/' . DEVDLINK_VERSION . '; +https://devdome.com)',
        'excluded_domains' => array(), // hosts skipped at extract time
        // 404 log retention + privacy
        'purge_days'       => 90,      // allowlist 30 | 90 | 180
        'fof_max_rows'     => 5000,    // hard row cap on the 404 table
        'fof_ignored_max_rows' => 500, // ignored rows are bounded too
        'fof_ignored_max_days' => 365, // ...and expire, their last_seen being frozen
        'referrer_mode'    => 'origin_path', // origin_path | origin | none (query is NEVER stored)
        'store_user_agent' => 1,       // 0 stores no user-agent string at all
        // scheduling / email
        'scheduled_rescan' => 'off',   // off | weekly | monthly
        'email_new_broken' => 0,
        'email_reported_scan_id' => 0,
        // job + error state
        'job'              => '',
        'error_log'        => array(),
        'last_error'       => '',
        'mutation_log'     => array(),
        'last_scan_id'     => 0,
        'last_scan_at'     => 0,
        // cached summary (written ONLY by devdlink_refresh_summary())
        'links_total'      => 0,
        'links_ok'         => 0,
        'links_broken'     => 0,
        'links_redirect'   => 0,
        'links_timeout'    => 0,
        'links_blocked'    => 0,
        'health_score'     => 100,
        'fof_paths'        => 0,
        'fof_human_hits'   => 0,
        'fof_bot_hits'     => 0,
        'fof_ignored'      => 0,
        'fof_hot_paths'    => 0,
    );
}

/** Seed missing settings (INSERT IGNORE — reactivation/upgrade never wipes a stored value). */
function devdlink_seed_settings()
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_settings';
    foreach (devdlink_default_settings() as $name => $value) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; values bound via prepare; one-time seed.
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (setting_name, setting_value) VALUES (%s, %s)",
            $name,
            is_array($value) ? serialize($value) : $value
        ));
    }
    unset($GLOBALS['devdlink_cache']);
}

/** Schedule the daily dispatchers (each honours its setting; if disabled it no-ops). */
function devdlink_schedule_events()
{
    if (!wp_next_scheduled('devdlink_scheduled_scan')) {
        wp_schedule_event(time() + 3600, 'daily', 'devdlink_scheduled_scan');
    }
    if (!wp_next_scheduled('devdlink_purge_sweep')) {
        wp_schedule_event(time() + 7200, 'daily', 'devdlink_purge_sweep');
    }
    if (!wp_next_scheduled('devdlink_summary_refresh')) {
        wp_schedule_event(time() + 10800, 'daily', 'devdlink_summary_refresh');
    }
}

/**
 * Data migrations that dbDelta cannot do. Resumable: each call does one BOUNDED chunk and
 * returns false while more work is left, so a front request is never held open and the
 * schema option only advances once the data actually matches the new contract.
 *
 * @param int $from the schema version currently recorded
 * @return bool true when nothing is left to migrate
 */
function devdlink_run_migrations($from)
{
    global $wpdb;

    if ((int) $from < 2) {
        // 0.1.x stored MD5 (32 hex) identities. Recompute them as SHA-256 in bounded chunks;
        // MySQL computes the same value as hash('sha256', url) for the stored bytes.
        $links = $wpdb->prefix . 'devdlink_links';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL; migration progress probe.
        $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$links} WHERE CHAR_LENGTH(url_hash) <> 64");
        if ($left > 0) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; limit bound via prepare; bounded resumable migration chunk.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$links} SET url_hash = LOWER(SHA2(url, 256)) WHERE CHAR_LENGTH(url_hash) <> 64 LIMIT %d",
                2000
            ));
            return ($left <= 2000);
        }
    }

    if ((int) $from < 3) {
        // 3a. The old 300s two-strike gap made every scan idle for minutes: sites still on the
        //     old seeded default move to the new default; a deliberately changed value stays.
        if (devdlink_get_int('strike_gap_seconds', 15) === 300) {
            devdlink_update_setting('strike_gap_seconds', 15);
        }
        // 3b. link_sources: the old (link_id, post_id) unique key blocks the per-usage rows.
        $sources = $wpdb->prefix . 'devdlink_link_sources';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema migration on the plugin's own table.
        $old_idx = $wpdb->get_var("SHOW INDEX FROM {$sources} WHERE Key_name = 'idx_link_post'");
        if ($old_idx) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off schema migration on the plugin's own table.
            $wpdb->query("ALTER TABLE {$sources} DROP INDEX idx_link_post");
        }
        // 3c. 404 log: identity moves from the 191-char path to a hash of the full path, so
        //     two long paths that share a prefix are no longer one row. Backfilled in chunks.
        $fof = $wpdb->prefix . 'devdlink_404s';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migration progress count.
        $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$fof} WHERE path_hash = ''");
        if ($left > 0) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded backfill; limit bound via prepare.
            $wpdb->query($wpdb->prepare("UPDATE {$fof} SET path_hash = LOWER(SHA2(path, 256)) WHERE path_hash = '' LIMIT %d", 2000));
            if ($left > 2000) {
                return false;
            }
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema migration on the plugin's own table.
        $uq = $wpdb->get_var("SHOW INDEX FROM {$fof} WHERE Key_name = 'uq_path_hash'");
        if (!$uq) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off schema migration on the plugin's own table.
            $wpdb->query("ALTER TABLE {$fof} ADD UNIQUE KEY uq_path_hash (path_hash)");
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off schema migration on the plugin's own table.
        $old_path_idx = $wpdb->get_var("SHOW INDEX FROM {$fof} WHERE Key_name = 'path'");
        if ($old_path_idx) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off schema migration on the plugin's own table.
            $wpdb->query("ALTER TABLE {$fof} DROP INDEX path");
        }
    }

    if ((int) $from < 4) {
        // 4a. The CREATE TABLE definition used to carry the pre-hash unique key on the 191-char path;
        //     dbDelta could have re-created it on a later activation and revived the
        //     191-character collision. The canonical key is uq_path_hash now; the old helper
        //     index goes, the plain path index dbDelta adds is fine.
        $fof = $wpdb->prefix . 'devdlink_404s';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time schema migration on the plugin's own table.
        $old_hash_idx = $wpdb->get_var("SHOW INDEX FROM {$fof} WHERE Key_name = 'idx_path_hash'");
        if ($old_hash_idx) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-time schema migration on the plugin's own table.
            $wpdb->query("ALTER TABLE {$fof} DROP INDEX idx_path_hash");
        }
        // 4b. Occurrence rows written before schema 3 all carry usage_type 'href'. Best effort:
        //     an image-only link's rows become 'img'. Mixed use cannot be recovered from the
        //     old data, so one fresh full scan is requested (flag shown on the Dashboard,
        //     cleared when a full scan completes).
        $links   = $wpdb->prefix . 'devdlink_links';
        $sources = $wpdb->prefix . 'devdlink_link_sources';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time data migration on the plugin's own tables.
        $wpdb->query("UPDATE {$sources} s INNER JOIN {$links} l ON l.id = s.link_id SET s.usage_type = l.link_type WHERE s.usage_type = 'href' AND l.link_type <> 'href'");
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time data migration on the plugin's own tables.
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$links}") > 0) {
            devdlink_update_setting('rescan_needed', 1);
        }
    }

    if ((int) $from < 5) {
        // 5. The last-reported-scan marker keeps the name of what it records now.
        $old_marker = devdlink_get_int('email_handed_to_mail_scan_id', 0);
        if ($old_marker > 0 && devdlink_get_int('email_reported_scan_id', 0) === 0) {
            devdlink_update_setting('email_reported_scan_id', $old_marker);
        }
    }

    return true;
}

/**
 * Create or upgrade this site's schema when the recorded version does not match the shipped
 * one. Cheap when nothing is due (one option read). Guarded by an atomic lease so two
 * concurrent requests cannot run dbDelta at the same time.
 */
function devdlink_maybe_upgrade()
{
    $have = (int) get_option('devdlink_schema', 0);
    if ($have === DEVDLINK_SCHEMA) {
        return;
    }

    // Atomic lease: add_option() succeeds for exactly one caller (option_name is UNIQUE).
    // The value is "<timestamp>|<owner token>" and a stale lease is taken over with a
    // compare-and-swap, so two requests that both read the same stale lock can never both
    // run the migration (same pattern as the tick lock).
    global $wpdb;
    $now  = time();
    $mine = $now . '|' . uniqid('u', true);
    if (!add_option('devdlink_upgrade_lock', $mine, '', 'no')) {
        wp_cache_delete('devdlink_upgrade_lock', 'options');
        $held_raw = (string) get_option('devdlink_upgrade_lock', '');
        $held = (int) $held_raw;
        if ($held && ($now - $held) < 300) {
            return; // another request is upgrading; this one just serves the page.
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic stale-lease takeover on the options table; values bound via prepare.
        $rows = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
            $mine,
            'devdlink_upgrade_lock',
            $held_raw
        ));
        if ($rows !== 1) {
            return; // someone else won the takeover.
        }
        wp_cache_delete('devdlink_upgrade_lock', 'options');
    }

    try {
        devdlink_install_tables();
        devdlink_seed_settings();
        if (devdlink_run_migrations($have)) {
            // Lazy provisioning (network sites beyond activation's first page, and every site
            // reaching a new schema) must leave the site with its cron events too; schema 6
            // exists so sites provisioned lazily before 1.4.13 pass through here once more.
            devdlink_schedule_events();
            update_option('devdlink_schema', DEVDLINK_SCHEMA, false);
        }
        // else: more chunks left — the next request resumes from the same recorded version.
    } finally {
        // Release only our own lease: a holder that ran past the TTL and was taken over must
        // not delete the new holder's lock.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership-checked lease release; values bound via prepare.
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'devdlink_upgrade_lock', $mine));
        wp_cache_delete('devdlink_upgrade_lock', 'options');
        if ((string) get_option('devdlink_upgrade_lock', '') === $mine) {
            delete_option('devdlink_upgrade_lock'); // still ours (cached row): release through the API too.
        }
    }
}

/** Everything one site needs: tables, defaults, schedules, recorded schema version. */
function devdlink_activate_site()
{
    devdlink_install_tables();
    devdlink_seed_settings();
    // Only stamp the schema when the migration reports it FINISHED - it works in bounded
    // batches and returns false when rows remain, in which case devdlink_maybe_upgrade()
    // continues it on later requests until it completes.
    if (devdlink_run_migrations((int) get_option('devdlink_schema', 0))) {
        update_option('devdlink_schema', DEVDLINK_SCHEMA, false);
    }
    devdlink_schedule_events();
}

/**
 * Activation. On a network activation every EXISTING site is provisioned here, bounded to
 * a batch so a large network cannot blow one request up; any site not reached (and any site
 * created later) provisions itself on its first load through devdlink_maybe_upgrade().
 *
 * @param bool $network_wide passed by WordPress
 */
function devdlink_activate($network_wide = false)
{
    if ($network_wide && is_multisite()) {
        $sites = get_sites(array('fields' => 'ids', 'number' => 200));
        foreach ($sites as $blog_id) {
            switch_to_blog((int) $blog_id);
            devdlink_activate_site();
            restore_current_blog();
        }
        return;
    }
    devdlink_activate_site();
}

/**
 * A site created while the plugin is network-active.
 *
 * @param WP_Site|int $site
 */
function devdlink_initialize_new_site($site)
{
    if (!is_multisite() || !function_exists('is_plugin_active_for_network')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (!is_multisite() || !is_plugin_active_for_network(plugin_basename(DEVDLINK_FILE))) {
        return;
    }
    $blog_id = is_object($site) && isset($site->blog_id) ? (int) $site->blog_id : (int) $site;
    if (!$blog_id) {
        return;
    }
    switch_to_blog($blog_id);
    devdlink_activate_site();
    restore_current_blog();
}

/** Per-site deactivation: schedules + locks only. Data survives a toggle. */
function devdlink_deactivate_site()
{
    wp_clear_scheduled_hook('devdlink_run_job');
    wp_clear_scheduled_hook('devdlink_scheduled_scan');
    wp_clear_scheduled_hook('devdlink_purge_sweep');
    wp_clear_scheduled_hook('devdlink_summary_refresh');
    delete_option('devdlink_tick_lock');
    delete_option('devdlink_start_lock');
    delete_option('devdlink_upgrade_lock');
}

/**
 * Deactivation.
 *
 * @param bool $network_wide passed by WordPress
 */
function devdlink_deactivate($network_wide = false)
{
    if ($network_wide && is_multisite()) {
        // Paginate through EVERY site: deactivation must clear cron events and locks on all
        // of them, not just the first page (activation's 200-site bound self-heals via lazy
        // provisioning; deactivation has no later chance).
        $offset = 0;
        do {
            $sites = get_sites(array('fields' => 'ids', 'number' => 200, 'offset' => $offset));
            foreach ($sites as $blog_id) {
                switch_to_blog((int) $blog_id);
                devdlink_deactivate_site();
                restore_current_blog();
            }
            $offset += 200;
        } while (count($sites) === 200);
    } else {
        devdlink_deactivate_site();
    }
    wp_cache_flush();
}

/** Suggested privacy-policy text: exactly what is stored, for how long, and what leaves. */
function devdlink_register_privacy_policy()
{
    if (!function_exists('wp_add_privacy_policy_content')) {
        return;
    }
    $content = '<p>' . wp_kses_post(__('DevDome Link Monitor logs requests to pages that do not exist (404s). For each missing path it stores the requested path with the query string removed, hit counters, the referring page reduced to its origin and path (query strings are never stored) and, unless you turn it off, the browser user-agent string. No IP address is stored and no cookie is set. These rows are deleted automatically after the retention window you choose (30, 90 or 180 days; ignored paths after one year) and are capped in number.', 'devdome-link-monitor')) . '</p>'
        . '<p>' . wp_kses_post(__('When you run a link scan, this site sends an HTTP request from your server to each URL found in your published content. Those target servers see your server IP address, the configured user-agent string and the URL itself - including anything personal or secret that a URL in your content happens to contain. Scan results (the URLs and their status) are stored in this site\'s database. The optional email summary is sent by DevDome to the connected account email and contains aggregate counts only; scanned URLs, anchor text and source post titles are not sent to DevDome.', 'devdome-link-monitor')) . '</p>';
    $content .= '<p>' . wp_kses_post(__('If this site is connected to a DevDome account (optional, started by an administrator pressing Connect), each completed link scan sends DevDome the site\'s DevDome identifiers (site ID and site token, in a request header), the scan number, aggregate scan metrics (links checked, healthy, broken, redirects, health score, newly broken count), whether the email summary is switched on and the address of the plugin\'s admin screen, so that DevDome can show the summary in the account dashboard and email it. It does not send scanned link URLs, anchor text, source post titles, 404 request paths, referrers, user agents or visitor IP addresses. Nothing is sent while the site is not connected.', 'devdome-link-monitor')) . '</p>';
    wp_add_privacy_policy_content('DevDome Link Monitor', $content);
}
