<?php
/**
 * Full data removal — runs only when the plugin is DELETED (not on deactivate).
 * Drops the custom tables, deletes options + the cached hub summary, clears scheduled hooks,
 * sweeps transients, loops multisite, coordinates shared-core cleanup, ends with wp_cache_flush().
 *
 * POLICY (documented in readme.txt): deleting the plugin deletes ALL of its data immediately,
 * on every site of a network, with no retain option. Deactivating keeps everything.
 * Only tables whose name starts with this site's own $wpdb->prefix . 'devdlink_' are
 * dropped, so another site's (or another plugin's) prefix is never touched.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/** Per-site cleanup (called once per site on multisite, or once on single-site). */
function devdlink_uninstall_site()
{
    global $wpdb;
    $p = $wpdb->prefix . 'devdlink_';

    foreach (array('settings', '404s', 'links', 'link_sources', 'scans') as $t) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix + hardcoded table name; DROP TABLE cannot be prepared/cached.
        $wpdb->query("DROP TABLE IF EXISTS {$p}{$t}");
    }

    delete_option('devdlink_tick_lock');
    delete_option('devdlink_start_lock');
    delete_option('devdlink_upgrade_lock');
    delete_option('devdlink_schema');
    delete_option('devdlink_cancelled_at');
    delete_option('devdlink_tick_key');

    // Sweep any leftover devdlink_* transients.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall transient sweep.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_devdlink\_%' OR option_name LIKE '\_transient\_timeout\_devdlink\_%'");

    wp_clear_scheduled_hook('devdlink_run_job');
    wp_clear_scheduled_hook('devdlink_scheduled_scan');
    wp_clear_scheduled_hook('devdlink_purge_sweep');
    wp_clear_scheduled_hook('devdlink_summary_refresh');
}

global $wpdb;
// Shared-core cleanup runs PER BLOG too: the connection state (site ID, site token, account,
// connection cache) is stored per blog, so a network uninstall must visit every blog.
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
if (is_multisite()) {
    $devdlink_offset = 0;
    do {
        $devdlink_sites = get_sites(array('fields' => 'ids', 'number' => 200, 'offset' => $devdlink_offset));
        foreach ($devdlink_sites as $devdlink_blog_id) {
            switch_to_blog((int) $devdlink_blog_id);
            devdlink_uninstall_site();
            devdcorev1_uninstall_cleanup('devdome-link-monitor/devdome-link-monitor.php');
            restore_current_blog();
        }
        $devdlink_offset += 200;
    } while (count($devdlink_sites) === 200);
} else {
    devdlink_uninstall_site();
}

// Coordinate shared-core cleanup (removes the core cron/option only if this is the LAST DevDome
// plugin still installed, so removing this one never orphans the core for the others).
require_once __DIR__ . '/lib/devdome-core/uninstall.php';
devdcorev1_uninstall_cleanup('devdome-link-monitor/devdome-link-monitor.php');

wp_cache_flush();
