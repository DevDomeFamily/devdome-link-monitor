<?php
/*
Plugin Name: DevDome Link Monitor
Plugin URI: https://devdome.com/
Description: Find broken links and monitor 404s with a user-agent bot/human split. Part of the DevDome suite.
Version: 1.6.0
Author: DevDome
Author URI: https://devdome.com
Text Domain: devdome-link-monitor
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.0
Requires PHP: 7.4
*/

if (!defined('ABSPATH')) {
    exit;
}

// The WordPress.org zip ships this marker file (defines DEVDCOREV1_WPORG_BUILD) so the
// same codebase can switch off self-hosted updates and other non-wp.org behavior.
if (file_exists(__DIR__ . '/wporg-build.php')) {
    require __DIR__ . '/wporg-build.php';
}

define('DEVDLINK_VERSION', '1.6.0');
define('DEVDLINK_DIR', plugin_dir_path(__FILE__));
define('DEVDLINK_URL', plugin_dir_url(__FILE__));
define('DEVDLINK_FILE', __FILE__);
define('DEVDLINK_PAGE', 'devdome-link-monitor');

// Shared DevDome core (vendored, version-guarded; only the highest copy across all installed
// DevDome plugins actually loads). Provides the suite hub, account/licensing seam, report
// contract and shared cron. Loaded FIRST, above every other require.
require_once DEVDLINK_DIR . 'lib/devdome-core/loader.php';

require_once DEVDLINK_DIR . 'includes/settings.php';
require_once DEVDLINK_DIR . 'includes/install.php';
require_once DEVDLINK_DIR . 'includes/helpers.php';
require_once DEVDLINK_DIR . 'includes/logger-404.php';
require_once DEVDLINK_DIR . 'includes/html.php';
require_once DEVDLINK_DIR . 'includes/extractor.php';
require_once DEVDLINK_DIR . 'includes/checker.php';
require_once DEVDLINK_DIR . 'includes/queue.php';
require_once DEVDLINK_DIR . 'includes/rest.php';
require_once DEVDLINK_DIR . 'includes/abilities.php';

if (is_admin()) {
    require_once DEVDLINK_DIR . 'includes/admin.php';
    require_once DEVDLINK_DIR . 'includes/fourohfour-admin.php';
}

/**
 * Plugin-level capability gate, split by what the caller is about to do so an agency can
 * grant "look at the link health" without also granting "rewrite published content" or
 * "cause outbound requests from this server".
 *
 * Contexts:
 *   view      — open the screen, read the summary and the link/404 lists
 *   scan      — start/pause/cancel scans and re-checks (causes outbound requests)
 *   data      — read and export the full 404 log (referrers, user agents)
 *   configure — change scanning, privacy and retention settings
 *   mutate    — edit/unlink/dismiss content through the plugin
 *
 * EVERY context defaults to manage_options. A filter may LOWER a context for a role, but
 * that only decides who may ask: content mutation is additionally gated per post by
 * devdlink_authorize_link_sources() (current_user_can('edit_post', $id)), which no filter
 * can bypass. The audit proved the single broad capability alone was not a boundary.
 *
 * @param string $context
 * @return string capability name
 */
function devdlink_capability($context = 'view')
{
    $contexts = array('view', 'scan', 'data', 'configure', 'mutate');
    $context = in_array((string) $context, $contexts, true) ? (string) $context : 'view';

    // Legacy filter first (released installs may already scope the whole plugin with it).
    $cap = apply_filters('devdlink_capability', 'manage_options');
    if (!is_string($cap) || $cap === '') {
        $cap = 'manage_options';
    }

    /**
     * Filter the capability required for ONE context.
     *
     * @param string $cap     capability name
     * @param string $context one of view|scan|data|configure|mutate
     */
    $cap = apply_filters('devdlink_capability_for', $cap, $context);
    return (is_string($cap) && $cap !== '') ? $cap : 'manage_options';
}

// DevDome Tools hub: register this plugin in the suite dashboard (decoupled — a new plugin is
// ~10 lines, zero hub edits). Tiles + health read ONLY the cached summary (no query on render).
add_filter('devdcorev1_suite_register', function ($r) {
    $r['devdome-link-monitor'] = array(
        'slug'     => 'devdome-link-monitor',
        'name'     => 'Link Monitor',
        'desc'     => 'Find broken links &amp; monitor 404s with a user-agent bot/human split.',
        'icon'     => 'dashicons-admin-links',
        'version'  => DEVDLINK_VERSION,
        'page'     => 'devdome-link-monitor',
        'position' => 85,
        'schema'   => 1,
        'tiles'    => function () {
            if (!function_exists('devdlink_hub_summary')) {
                return array();
            }
            $s      = devdlink_hub_summary();
            $href   = 'admin.php?page=devdome-link-monitor';
            $broken = (int) $s['links_broken'];
            $human  = (int) $s['fof_human_hits'];
            $health = (float) $s['health_score'];
            return array(
                array('label' => 'Broken links',     'value' => $broken, 'fmt' => 'int', 'state' => $broken ? 'warn' : 'good', 'href' => $href),
                array('label' => '404 hits (human)', 'value' => $human,  'fmt' => 'int', 'state' => $human ? 'warn' : 'idle', 'href' => $href),
                array('label' => 'Link health',      'value' => $health, 'fmt' => 'pct', 'state' => $health >= 75 ? 'good' : ($health >= 50 ? 'warn' : 'urgent'), 'href' => $href),
            );
        },
        'health'   => function () {
            if (!function_exists('devdlink_hub_summary')) {
                return null;
            }
            $s      = devdlink_hub_summary();
            $href   = admin_url('admin.php?page=devdome-link-monitor');
            $score  = (int) round((float) $s['health_score']);
            $issues = array();
            if ((int) $s['last_scan_id'] === 0) {
                $issues[] = array(
                    'problem'        => 'Your site\'s links have not been scanned yet.',
                    'why_it_matters' => 'Broken links hurt SEO and frustrate visitors, and you will not know until someone complains.',
                    'fix'            => 'Run a link scan.',
                    'actions'        => array(array('label' => 'Scan now', 'href' => $href)),
                );
            } else {
                if ((int) $s['links_broken'] > 0) {
                    $issues[] = array(
                        'problem'        => 'Broken links found in your content.',
                        'why_it_matters' => (int) $s['links_broken'] . ' links failed two checks separated in time. Timeouts, DNS/TLS errors and access-blocked responses are excluded.',
                        'fix'            => 'Review the broken links and fix, unlink or dismiss them.',
                        'actions'        => array(array('label' => 'Review links', 'href' => $href . '&lm_tab=links')),
                    );
                }
            }
            if ((int) $s['fof_hot_paths'] > 0) {
                $issues[] = array(
                    'problem'        => 'Missing pages keep being requested by non-crawler user agents.',
                    'why_it_matters' => (int) $s['fof_hot_paths'] . ' 404 paths have been hit 10+ times by user agents that did not match a known crawler.',
                    'fix'            => 'Review the 404 log and add redirects for the hot paths.',
                    'actions'        => array(array('label' => 'View 404 log', 'href' => $href . '&lm_tab=fof')),
                );
            }
            return array(
                'score'       => $score,
                'status'      => $score >= 75 ? 'good' : ($score >= 50 ? 'warn' : 'urgent'),
                'scope_label' => 'Links',
                'summary'     => '',
                'issues'      => $issues,
            );
        },
    );
    return $r;
});

// Recent-activity digest section (read-only, from the cached summary).
add_filter('devdcorev1_suite_report_sections', function ($s) {
    if (!function_exists('devdlink_hub_summary')) {
        return $s;
    }
    $sum = devdlink_hub_summary();
    $s[] = array('title' => 'Link Monitor', 'lines' => array(
        (int) $sum['links_total'] . ' links checked, ' . (int) $sum['links_broken'] . ' broken',
        (int) $sum['links_redirect'] . ' redirects, ' . (int) $sum['links_blocked'] . ' blocked (anti-bot)',
        (int) $sum['fof_paths'] . ' 404 paths (' . (int) $sum['fof_human_hits'] . ' human hits, ' . (int) $sum['fof_bot_hits'] . ' bot hits)',
    ));
    return $s;
});

register_activation_hook(__FILE__, 'devdlink_activate');
register_deactivation_hook(__FILE__, 'devdlink_deactivate');

// Schema creation/upgrade runs on every normal load, not only when an admin happens to open
// a screen: a network-activated new site, a file-copy upgrade and a restored database all
// reach here first. The runner is a single option read when nothing is due.
add_action('plugins_loaded', 'devdlink_maybe_upgrade', 5);

// A site created while the plugin is network-active gets its tables the first time it is
// loaded (above); provisioning it eagerly avoids a first request that has no tables at all.
add_action('wp_initialize_site', 'devdlink_initialize_new_site', 20, 1);

// Privacy policy suggestion (what the 404 log keeps, for how long, and what leaves the site).
add_action('admin_init', 'devdlink_register_privacy_policy');

/* DevDome suite self-hosted updates (admin/cron only - never on the front end). The wp.org
 * build drops the updater file from the zip and defines DEVDCOREV1_WPORG_BUILD (wp.org itself
 * delivers updates there), so the whole block is skipped. */
if ( ! defined( 'DEVDCOREV1_WPORG_BUILD' ) && file_exists( __DIR__ . '/includes/class-devdome-suite-updater.php' ) ) {
	require_once __DIR__ . '/includes/class-devdome-suite-updater.php';
	if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
		new DEVDLINK_Suite_Updater( __FILE__, 'devdome-link-monitor', 'https://api.devdome.com/plugin-updates/devdome-link-monitor.json' );
	}
}
