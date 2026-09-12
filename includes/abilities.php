<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): the whole plugin exposed as typed, discoverable
 * abilities for AI agents and MCP clients (through the official WordPress MCP Adapter).
 *
 * Coverage = every action of the plugin's screens and REST controller (audited from the code,
 * 2026-09-10): link summary and health, the links list with every filter, one link with its
 * sources, scan start (full / recheck), pause / resume / cancel, progress, scan history, recheck
 * one link, edit a link's URL in every post it appears in, unlink, dismiss, the 404 log with every
 * filter, the redirect suggestion for a 404, ignore / unignore / delete a 404, run the retention
 * sweep, the two CSV exports as rows, every setting (read and update), the last error and its
 * dismissal, the error and mutation logs, and a summary recount.
 *
 * Nothing here is new logic: every ability calls the same functions the REST controller and the
 * admin page use, under the same capability contexts (view / scan / data / configure / mutate),
 * and content edits keep the per-post edit_post check no filter can bypass. Destructive abilities
 * (delete a 404, purge) are annotated destructive. On WordPress older than 6.9 the API does not
 * exist and this file registers nothing.
 */

defined('ABSPATH') || exit;

/** The ability ids this plugin registers, in the order they are listed to clients. */
function devdlink_ability_ids()
{
    return array(
        'devdome-link-monitor/get-link-summary',
        'devdome-link-monitor/get-broken-links',
        'devdome-link-monitor/get-link',
        'devdome-link-monitor/get-404s',
        'devdome-link-monitor/get-404-redirect-suggestion',
        'devdome-link-monitor/get-scan-progress',
        'devdome-link-monitor/get-scan-history',
        'devdome-link-monitor/get-settings',
        'devdome-link-monitor/export-links',
        'devdome-link-monitor/export-404s',
        'devdome-link-monitor/get-activity-log',
        'devdome-link-monitor/run-link-scan',
        'devdome-link-monitor/control-link-scan',
        'devdome-link-monitor/recheck-link',
        'devdome-link-monitor/edit-link-url',
        'devdome-link-monitor/unlink',
        'devdome-link-monitor/dismiss-link',
        'devdome-link-monitor/set-404-ignored',
        'devdome-link-monitor/delete-404',
        'devdome-link-monitor/purge-404s',
        'devdome-link-monitor/update-settings',
        'devdome-link-monitor/clear-last-error',
        'devdome-link-monitor/refresh-summary',
    );
}

add_action('wp_abilities_api_categories_init', 'devdlink_register_ability_category');
function devdlink_register_ability_category()
{
    if (!function_exists('wp_register_ability_category')) {
        return;
    }
    wp_register_ability_category('devdome-link-monitor', array(
        'label'       => __('DevDome Link Monitor', 'devdome-link-monitor'),
        'description' => __('Broken link checker and 404 monitor for WordPress: link health, every checked link with its pages, scans (start, pause, resume, cancel), fixing links (edit URL, unlink, dismiss), the 404 log (ignore, delete, redirect suggestions, purge), exports, settings and logs.', 'devdome-link-monitor'),
    ));
}

/* ------------------------------ permissions ------------------------------ */

function devdlink_ability_can_view()      { return current_user_can(devdlink_capability('view')); }
function devdlink_ability_can_data()      { return current_user_can(devdlink_capability('data')); }
function devdlink_ability_can_scan()      { return current_user_can(devdlink_capability('scan')); }
function devdlink_ability_can_configure() { return current_user_can(devdlink_capability('configure')); }
function devdlink_ability_can_mutate()    { return current_user_can(devdlink_capability('mutate')); }

/**
 * $kind: read (no change) / add (additive, not idempotent) / modify (changes state, safe to
 * repeat) / destroy (irreversible). destructive follows the WordPress meaning: false = additive
 * only, null = modifies, true = destructive.
 */
function devdlink_ability_meta($kind)
{
    $map = array(
        'read'    => array('readonly' => true,  'destructive' => false, 'idempotent' => true),
        'add'     => array('readonly' => false, 'destructive' => false, 'idempotent' => false),
        'modify'  => array('readonly' => false, 'destructive' => null,  'idempotent' => true),
        'destroy' => array('readonly' => false, 'destructive' => true,  'idempotent' => true),
    );
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => isset($map[$kind]) ? $map[$kind] : $map['modify'],
        'mcp'          => array('type' => 'tool'),
    );
}

/* ------------------------------ shared schemas ------------------------------ */

function devdlink_ability_link_schema()
{
    return array('type' => 'object', 'properties' => array(
        'id'            => array('type' => 'integer'),
        'url'           => array('type' => 'string'),
        'host'          => array('type' => 'string'),
        'internal'      => array('type' => 'boolean'),
        'type'          => array('type' => 'string', 'description' => 'href (a link) or img (an image source).'),
        'anchor'        => array('type' => 'string'),
        'status'        => array('type' => 'string', 'enum' => array('pending', 'suspect', 'ok', 'broken', 'redirect', 'timeout', 'blocked', 'dismissed')),
        'error_class'   => array('type' => 'string', 'enum' => array('none', 'gone', 'http_4xx', 'http_5xx', 'auth', 'ratelimit', 'antibot', 'transport', 'dns', 'tls', 'connect', 'timeout', 'protocol', 'policy')),
        'status_label'  => array('type' => 'string'),
        'status_short'  => array('type' => 'string'),
        'status_detail' => array('type' => 'string'),
        'error_message' => array('type' => 'string'),
        'http_code'     => array('type' => 'integer'),
        'redirect_url'  => array('type' => 'string'),
        'redirect_hops' => array('type' => 'integer'),
        'checked_at'    => array('type' => 'string'),
        'sources'       => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
            'post_id' => array('type' => 'integer'), 'title' => array('type' => 'string'), 'edit_url' => array('type' => 'string'), 'view_url' => array('type' => 'string'),
            'type' => array('type' => 'string'), 'usage' => array('type' => 'string', 'enum' => array('href', 'img')), 'anchor' => array('type' => 'string'), 'occurrences' => array('type' => 'integer'),
        ))),
    ));
}

function devdlink_ability_404_schema()
{
    return array('type' => 'object', 'properties' => array(
        'id'         => array('type' => 'integer'),
        'path'       => array('type' => 'string'),
        'human_hits' => array('type' => 'integer'),
        'bot_hits'   => array('type' => 'integer'),
        'referrer'   => array('type' => 'string', 'description' => 'Last referrer, query string and secrets removed.'),
        'ua'         => array('type' => 'string', 'description' => 'Last user agent when the site stores it.'),
        'ignored'    => array('type' => 'boolean'),
        'first_seen' => array('type' => 'string'),
        'last_seen'  => array('type' => 'string'),
    ));
}

function devdlink_ability_progress_schema()
{
    return array('type' => 'object', 'properties' => array(
        'active'    => array('type' => 'boolean'),
        'type'      => array('type' => 'string', 'description' => 'scan (full) or recheck.'),
        'status'    => array('type' => 'string', 'description' => 'idle, running, paused, finalizing, completed or cancelled.'),
        'phase'     => array('type' => 'string', 'description' => 'extract, check, recheck or finalize.'),
        'processed' => array('type' => 'integer'),
        'total'     => array('type' => 'integer'),
        'percent'   => array('type' => 'number'),
        'errors'    => array('type' => 'integer'),
        'eta'       => array('type' => array('integer', 'null'), 'description' => 'Seconds left, when known.'),
        'scan_id'   => array('type' => 'integer'),
        'message'   => array('type' => 'string'),
    ));
}

function devdlink_ability_settings_properties()
{
    return array(
        'check_timeout'     => array('type' => 'integer', 'minimum' => 3, 'maximum' => 30, 'description' => 'Seconds each link check may take.'),
        'scan_chunk_size'   => array('type' => 'integer', 'minimum' => 10, 'maximum' => 500, 'description' => 'Posts parsed per scan slice.'),
        'check_batch_size'  => array('type' => 'integer', 'minimum' => 3, 'maximum' => 50, 'description' => 'Links checked per slice.'),
        'user_agent'        => array('type' => 'string', 'description' => 'User agent sent on every check; empty restores the default.'),
        'excluded_domains'  => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'Hosts never scanned (subdomains included); URLs are reduced to their host.'),
        'purge_days'        => array('type' => 'integer', 'enum' => array(30, 90, 180), 'description' => '404 log retention for active rows.'),
        'referrer_mode'     => array('type' => 'string', 'enum' => array('origin_path', 'origin', 'none'), 'description' => 'How much of a 404 referrer is stored (the query string never is).'),
        'store_user_agent'  => array('type' => 'boolean', 'description' => 'Keep the last user agent per 404 path.'),
        'scheduled_rescan'  => array('type' => 'string', 'enum' => array('off', 'weekly', 'monthly')),
        'email_new_broken'  => array('type' => 'boolean', 'description' => 'Email a summary when a scan finds new broken links; needs a connected DevDome account.'),
    );
}

/* ------------------------------- registration ------------------------------- */

add_action('wp_abilities_api_init', 'devdlink_register_abilities');
function devdlink_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }
    // An empty properties list must be a PHP array, not stdClass: core validates input by array
    // access on it and an unexpected argument would otherwise raise a type error.
    $empty = array('type' => 'object', 'properties' => array(), 'additionalProperties' => false);
    $paging = array(
        'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1),
        'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50),
    );
    $link_id = array('link_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'The link id from get-broken-links.'));
    $fof_id  = array('id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'The 404 entry id from get-404s.'));
    $link    = devdlink_ability_link_schema();
    $fof     = devdlink_ability_404_schema();
    $prog    = devdlink_ability_progress_schema();

    $reg = function ($id, $label, $desc, $in, $out, $cb, $perm, $kind) {
        wp_register_ability($id, array(
            'label'               => $label,
            'description'         => $desc,
            'category'            => 'devdome-link-monitor',
            'input_schema'        => $in,
            'output_schema'       => $out,
            'execute_callback'    => $cb,
            'permission_callback' => $perm,
            'meta'                => devdlink_ability_meta($kind),
        ));
    };

    $reg('devdome-link-monitor/get-link-summary', __('Get link health summary', 'devdome-link-monitor'),
        __('Get the WordPress site link health summary from DevDome Link Monitor: link health score, totals of ok, broken, redirected, unverified (timeout) and blocked links from the last completed link scan, when it ran, 404 monitor totals (paths, human hits, bot hits, ignored, hot paths with 10+ human hits), whether a full rescan is needed, whether a scan is running, the last persisted error, the last summary email, and the schedule and email settings. Read only, fast.', 'devdome-link-monitor'),
        $empty, array('type' => 'object', 'properties' => array(
            'health_score' => array('type' => 'number'), 'last_scan_id' => array('type' => 'integer'), 'last_scan_at' => array('type' => 'string'),
            'links_total' => array('type' => 'integer'), 'links_ok' => array('type' => 'integer'), 'links_broken' => array('type' => 'integer'), 'links_redirect' => array('type' => 'integer'),
            'links_timeout' => array('type' => 'integer'), 'links_blocked' => array('type' => 'integer'),
            'fof_paths' => array('type' => 'integer'), 'fof_human_hits' => array('type' => 'integer'), 'fof_bot_hits' => array('type' => 'integer'), 'fof_ignored' => array('type' => 'integer'), 'fof_hot_paths' => array('type' => 'integer'),
            'rescan_needed' => array('type' => 'boolean'), 'scan_running' => array('type' => 'boolean'),
            'last_error' => array('type' => array('object', 'null')), 'last_email' => array('type' => 'object'),
            'scheduled_rescan' => array('type' => 'string'), 'email_new_broken' => array('type' => 'boolean'), 'account_connected' => array('type' => 'boolean'),
        )), 'devdlink_ability_get_summary', 'devdlink_ability_can_view', 'read');

    $reg('devdome-link-monitor/get-broken-links', __('Get checked links', 'devdome-link-monitor'),
        __('Find broken links on this WordPress site. Returns links from the last completed link scan (or a given scan_id) with status, HTTP code, error class and message, redirect target, anchor text and the pages (posts) they appear on. filter: broken (default, confirmed dead after two failed checks), redirects, timeouts (unverified: timeouts, DNS or TLS errors), blocked (could not verify: 401, 403, 429, anti-bot), internal, external, or all. search matches the URL. Read only.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array_merge(array(
            'filter'  => array('type' => 'string', 'enum' => array('broken', 'redirects', 'timeouts', 'blocked', 'internal', 'external', 'all'), 'default' => 'broken'),
            'search'  => array('type' => 'string', 'default' => ''),
            'scan_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'A scan id from get-scan-history; default = the last completed full scan.'),
        ), $paging), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array(
            'scan_id' => array('type' => 'integer'), 'filter' => array('type' => 'string'), 'total' => array('type' => 'integer'), 'page' => array('type' => 'integer'), 'pages' => array('type' => 'integer'),
            'counts' => array('type' => 'object', 'properties' => array('all' => array('type' => 'integer'), 'broken' => array('type' => 'integer'), 'redirects' => array('type' => 'integer'), 'timeouts' => array('type' => 'integer'), 'blocked' => array('type' => 'integer'), 'internal' => array('type' => 'integer'), 'external' => array('type' => 'integer'))),
            'items' => array('type' => 'array', 'items' => $link),
        )), 'devdlink_ability_get_links', 'devdlink_ability_can_view', 'read');

    $reg('devdome-link-monitor/get-link', __('Get one link', 'devdome-link-monitor'),
        __('Get one checked link by its id with its full status and every page it appears in (post id, title, edit and view URLs, usage as link or image, occurrences). Read only.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => $link_id, 'required' => array('link_id'), 'additionalProperties' => false),
        $link, 'devdlink_ability_get_link', 'devdlink_ability_can_view', 'read');

    $reg('devdome-link-monitor/get-404s', __('Get 404 errors', 'devdome-link-monitor'),
        __('Get the 404 error log of this WordPress site: every missing-page path visitors or bots requested, with human hits and bot hits kept apart, last referrer, first and last seen. humans_only = only paths real people hit; ignored = exclude (default), include, or only ignored paths; orderby human_hits, bot_hits or last_seen; search matches the path. Read only.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array_merge(array(
            'humans_only' => array('type' => 'boolean', 'default' => false),
            'ignored'     => array('type' => 'string', 'enum' => array('exclude', 'include', 'only'), 'default' => 'exclude'),
            'orderby'     => array('type' => 'string', 'enum' => array('human_hits', 'bot_hits', 'last_seen'), 'default' => 'human_hits'),
            'order'       => array('type' => 'string', 'enum' => array('desc', 'asc'), 'default' => 'desc'),
            'search'      => array('type' => 'string', 'default' => ''),
        ), $paging), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array(
            'total' => array('type' => 'integer'), 'page' => array('type' => 'integer'), 'pages' => array('type' => 'integer'),
            'counts' => array('type' => 'object', 'properties' => array('all' => array('type' => 'integer'), 'humans' => array('type' => 'integer'), 'ignored' => array('type' => 'integer'))),
            'classifier' => array('type' => 'string', 'description' => 'How bot and human hits are told apart.'),
            'items' => array('type' => 'array', 'items' => $fof),
        )), 'devdlink_ability_get_404s', 'devdlink_ability_can_data', 'read');

    $reg('devdome-link-monitor/get-404-redirect-suggestion', __('Suggest a redirect for a 404', 'devdome-link-monitor'),
        __('For one 404 entry, suggest the published page whose slug matches the missing path best (60% similarity or better): its URL and title, a ready .htaccess Redirect 301 line, and a prefilled DevDome Redirect Manager link when that plugin is active. Read only.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => $fof_id, 'required' => array('id'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('id' => array('type' => 'integer'), 'path' => array('type' => 'string'), 'suggestion' => array('type' => array('object', 'null'), 'properties' => array('url' => array('type' => 'string'), 'label' => array('type' => 'string'), 'rm_url' => array('type' => 'string'), 'htaccess' => array('type' => 'string'))))),
        'devdlink_ability_404_suggest', 'devdlink_ability_can_data', 'read');

    $reg('devdome-link-monitor/get-scan-progress', __('Get link scan progress', 'devdome-link-monitor'),
        __('Check whether a link scan is running, paused or finishing on this WordPress site and how far it is: type, phase, percent, processed and total counts, errors, estimated seconds left, message. Read only.', 'devdome-link-monitor'),
        $empty, $prog, 'devdlink_ability_get_progress', 'devdlink_ability_can_view', 'read');

    $reg('devdome-link-monitor/get-scan-history', __('Get scan history', 'devdome-link-monitor'),
        __('List previous link scans, newest first: id, type (full or recheck), status, when started and finished, posts and links checked, ok, broken, redirect, timeout and blocked counts and how many links were newly broken compared with the previous scan. Read only.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array('limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 12)), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
            'id' => array('type' => 'integer'), 'type' => array('type' => 'string'), 'status' => array('type' => 'string'), 'started_at' => array('type' => 'string'), 'finished_at' => array('type' => 'string'),
            'total_posts' => array('type' => 'integer'), 'total_links' => array('type' => 'integer'), 'ok' => array('type' => 'integer'), 'broken' => array('type' => 'integer'), 'redirect' => array('type' => 'integer'), 'timeout' => array('type' => 'integer'), 'blocked' => array('type' => 'integer'), 'new_broken' => array('type' => 'integer'),
        ))))), 'devdlink_ability_get_history', 'devdlink_ability_can_view', 'read');

    $reg('devdome-link-monitor/get-settings', __('Get Link Monitor settings', 'devdome-link-monitor'),
        __('Read every Link Monitor setting: check timeout, posts per scan slice, links per check slice, user agent, excluded domains, 404 retention days, referrer storage mode, user agent storage, scheduled rescan cadence, email on new broken links, plus the bot classifier in use and whether a DevDome account is connected. Read only.', 'devdome-link-monitor'),
        $empty, array('type' => 'object', 'properties' => array_merge(devdlink_ability_settings_properties(), array('classifier' => array('type' => 'string'), 'account_connected' => array('type' => 'boolean')))),
        'devdlink_ability_get_settings', 'devdlink_ability_can_configure', 'read');

    $reg('devdome-link-monitor/export-links', __('Export flagged links', 'devdome-link-monitor'),
        __('Export every flagged link of the last completed scan (broken, redirected, unverified, blocked) as rows with the same columns as the CSV export in wp-admin: url, status, reason, http_code, anchor, found_in (page titles), redirect_url, checked_at. Up to 5000 rows. Read only.', 'devdome-link-monitor'),
        $empty, array('type' => 'object', 'properties' => array('total' => array('type' => 'integer'), 'columns' => array('type' => 'array', 'items' => array('type' => 'string')), 'rows' => array('type' => 'array'))),
        'devdlink_ability_export_links', 'devdlink_ability_can_data', 'read');

    $reg('devdome-link-monitor/export-404s', __('Export the 404 log', 'devdome-link-monitor'),
        __('Export the whole 404 log as rows with the same columns as the CSV export in wp-admin: path, human_hits, bot_hits, first_seen, last_seen, last_referrer (redacted), ignored. Up to 5000 rows. Read only.', 'devdome-link-monitor'),
        $empty, array('type' => 'object', 'properties' => array('total' => array('type' => 'integer'), 'columns' => array('type' => 'array', 'items' => array('type' => 'string')), 'rows' => array('type' => 'array'))),
        'devdlink_ability_export_404s', 'devdlink_ability_can_data', 'read');

    $reg('devdome-link-monitor/get-activity-log', __('Get error and change logs', 'devdome-link-monitor'),
        __('Read the plugin logs: the structured error log (last 50 scan or database errors with code, message and context) and the mutation log (last 100 content changes made through the plugin: URL edits, unlinks, 404 deletions, with outcome and post ids). Read only.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array('log' => array('type' => 'string', 'enum' => array('errors', 'mutations', 'both'), 'default' => 'both'), 'limit' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25)), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('errors' => array('type' => 'array'), 'mutations' => array('type' => 'array'))),
        'devdlink_ability_get_logs', 'devdlink_ability_can_view', 'read');

    $reg('devdome-link-monitor/run-link-scan', __('Run a link scan', 'devdome-link-monitor'),
        __('Start a broken link scan of this WordPress site. mode full (default) extracts every link from all published content and checks each one; mode recheck re-verifies only the links that were broken, unverified or blocked in the last scan. The scan runs in the background in small slices; use get-scan-progress to follow it and get-broken-links when it completes. Fails with a clear message if a scan is already running or paused. Sends HTTP requests to the linked sites, changes no content.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array('mode' => array('type' => 'string', 'enum' => array('full', 'recheck'), 'default' => 'full')), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array_merge(array('started' => array('type' => 'boolean')), $prog['properties'])),
        'devdlink_ability_run_scan', 'devdlink_ability_can_scan', 'modify');

    $reg('devdome-link-monitor/control-link-scan', __('Pause, resume or cancel the scan', 'devdome-link-monitor'),
        __('Control the running link scan: pause (finishes the current slice, then waits), resume, or cancel (stops it and discards the partial result; the last completed scan stays). Same as the Pause, Resume and Cancel buttons.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array('action' => array('type' => 'string', 'enum' => array('pause', 'resume', 'cancel'))), 'required' => array('action'), 'additionalProperties' => false),
        $prog, 'devdlink_ability_control_scan', 'devdlink_ability_can_scan', 'modify');

    $reg('devdome-link-monitor/recheck-link', __('Recheck one link', 'devdome-link-monitor'),
        __('Check one link again right now by its link id and return its updated status. Use it to confirm whether a link reported broken or unverified is really dead. Sends one HTTP request to the link, changes no content.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => $link_id, 'required' => array('link_id'), 'additionalProperties' => false),
        $link, 'devdlink_ability_recheck_link', 'devdlink_ability_can_scan', 'modify');

    $reg('devdome-link-monitor/edit-link-url', __('Replace a link URL in content', 'devdome-link-monitor'),
        __('Replace a broken or redirected link with a new URL in every published post or page it appears in, then recheck the new URL. The new URL must be http(s). Refused unless the current user may edit every post the link appears in; refused as duplicate when the new URL is already tracked. Reports how many posts were updated. Same as the pencil (Edit URL) action in wp-admin.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array_merge($link_id, array('new_url' => array('type' => 'string', 'description' => 'The replacement URL, http or https.'))), 'required' => array('link_id', 'new_url'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('updated_posts' => array('type' => 'integer'), 'link' => $link)),
        'devdlink_ability_edit_url', 'devdlink_ability_can_mutate', 'modify');

    $reg('devdome-link-monitor/unlink', __('Remove a link, keep its text', 'devdome-link-monitor'),
        __('Remove the anchor tags of one link from every published post it appears in, keeping the anchor text. Only anchor links can be unlinked (not image sources). Refused unless the current user may edit every post involved. Same as Unlink in wp-admin.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => $link_id, 'required' => array('link_id'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('updated_posts' => array('type' => 'integer'))),
        'devdlink_ability_unlink', 'devdlink_ability_can_mutate', 'modify');

    $reg('devdome-link-monitor/dismiss-link', __('Dismiss a link', 'devdome-link-monitor'),
        __('Hide one link from the lists without touching the content (status dismissed). It reappears if a later full scan finds it again. Same as Dismiss in wp-admin.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => $link_id, 'required' => array('link_id'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('link_id' => array('type' => 'integer'), 'status' => array('type' => 'string'))),
        'devdlink_ability_dismiss', 'devdlink_ability_can_mutate', 'modify');

    $reg('devdome-link-monitor/set-404-ignored', __('Ignore or stop ignoring a 404 path', 'devdome-link-monitor'),
        __('Mark one 404 entry as ignored (frozen: no more hits counted, kept out of the default list) or stop ignoring it. Same as Ignore / Stop ignoring in wp-admin.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => array_merge($fof_id, array('ignored' => array('type' => 'boolean'))), 'required' => array('id', 'ignored'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('id' => array('type' => 'integer'), 'ignored' => array('type' => 'boolean'))),
        'devdlink_ability_404_ignore', 'devdlink_ability_can_mutate', 'modify');

    $reg('devdome-link-monitor/delete-404', __('Delete a 404 entry', 'devdome-link-monitor'),
        __('Permanently delete one 404 log entry by id. It is logged in the mutation log and cannot be undone; the path is logged again if it is hit again. Same as Delete in wp-admin.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => $fof_id, 'required' => array('id'), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array('deleted' => array('type' => 'boolean'), 'id' => array('type' => 'integer'))),
        'devdlink_ability_404_delete', 'devdlink_ability_can_mutate', 'destroy');

    $reg('devdome-link-monitor/purge-404s', __('Run the 404 retention sweep now', 'devdome-link-monitor'),
        __('Run the daily 404 retention sweep immediately: delete active entries older than the retention setting, ignored entries older than a year or above their cap, and trim the table to its row cap. Cannot be undone.', 'devdome-link-monitor'),
        $empty, array('type' => 'object', 'properties' => array('purged' => array('type' => 'boolean'), 'rows_before' => array('type' => 'integer'), 'rows_after' => array('type' => 'integer'))),
        'devdlink_ability_purge_404s', 'devdlink_ability_can_mutate', 'destroy');

    $reg('devdome-link-monitor/update-settings', __('Update Link Monitor settings', 'devdome-link-monitor'),
        __('Change any Link Monitor settings; only the keys you pass change. check_timeout 3 to 30 seconds, scan_chunk_size 10 to 500, check_batch_size 3 to 50, user_agent, excluded_domains (hosts), purge_days 30, 90 or 180, referrer_mode, store_user_agent, scheduled_rescan off, weekly or monthly, email_new_broken (only on a site with a connected DevDome account). Same validation as the Settings screen.', 'devdome-link-monitor'),
        array('type' => 'object', 'properties' => devdlink_ability_settings_properties(), 'additionalProperties' => false),
        array('type' => 'object', 'properties' => array_merge(array('updated' => array('type' => 'boolean')), devdlink_ability_settings_properties())),
        'devdlink_ability_update_settings', 'devdlink_ability_can_configure', 'modify');

    $reg('devdome-link-monitor/clear-last-error', __('Dismiss the last error', 'devdome-link-monitor'),
        __('Dismiss the persisted error banner shown on the Overview after a failed scan. Same as the Dismiss link in wp-admin; the error stays in the error log.', 'devdome-link-monitor'),
        $empty, array('type' => 'object', 'properties' => array('cleared' => array('type' => 'boolean'))),
        'devdlink_ability_clear_error', 'devdlink_ability_can_view', 'modify');

    $reg('devdome-link-monitor/refresh-summary', __('Recount the summary', 'devdome-link-monitor'),
        __('Recount the cached link and 404 totals from the tables (the same nightly recount the plugin schedules) and return the fresh summary. Use it if numbers look stale.', 'devdome-link-monitor'),
        $empty, array('type' => 'object', 'properties' => array('refreshed' => array('type' => 'boolean'))),
        'devdlink_ability_refresh_summary', 'devdlink_ability_can_view', 'modify');
}

/* ------------------------------- helpers -------------------------------- */

function devdlink_ability_account_connected()
{
    if (!function_exists('devdcorev1_connection_state')) {
        return false;
    }
    $c = devdcorev1_connection_state();
    return is_array($c) && !empty($c['ok']);
}

function devdlink_ability_format_404($r)
{
    $r = (array) $r;
    return array(
        'id'         => isset($r['id']) ? (int) $r['id'] : 0,
        'path'       => isset($r['path']) ? (string) $r['path'] : '',
        'human_hits' => isset($r['human_hits']) ? (int) $r['human_hits'] : 0,
        'bot_hits'   => isset($r['bot_hits']) ? (int) $r['bot_hits'] : 0,
        'referrer'   => isset($r['last_referrer']) ? devdlink_redact_secrets((string) $r['last_referrer']) : '',
        'ua'         => isset($r['last_ua']) ? devdlink_redact_secrets((string) $r['last_ua']) : '',
        'ignored'    => !empty($r['ignored']),
        'first_seen' => isset($r['first_seen']) ? (string) $r['first_seen'] : '',
        'last_seen'  => isset($r['last_seen']) ? (string) $r['last_seen'] : '',
    );
}

/* ------------------------------- callbacks ------------------------------- */

function devdlink_ability_get_summary($input = array())
{
    $s = devdlink_hub_summary();
    $job = devdlink_get_job();
    $err = function_exists('devdlink_last_error') ? devdlink_last_error() : '';
    return array(
        'health_score'     => (float) $s['health_score'],
        'last_scan_id'     => (int) $s['last_scan_id'],
        'last_scan_at'     => $s['last_scan_at'] ? gmdate('c', (int) $s['last_scan_at']) : '',
        'links_total'      => (int) $s['links_total'],
        'links_ok'         => (int) $s['links_ok'],
        'links_broken'     => (int) $s['links_broken'],
        'links_redirect'   => (int) $s['links_redirect'],
        'links_timeout'    => (int) $s['links_timeout'],
        'links_blocked'    => (int) $s['links_blocked'],
        'fof_paths'        => (int) $s['fof_paths'],
        'fof_human_hits'   => (int) $s['fof_human_hits'],
        'fof_bot_hits'     => (int) $s['fof_bot_hits'],
        'fof_ignored'      => (int) $s['fof_ignored'],
        'fof_hot_paths'    => (int) $s['fof_hot_paths'],
        'rescan_needed'    => (int) devdlink_get_int('rescan_needed', 0) === 1,
        'scan_running'     => (bool) ($job && in_array($job['status'], array('running', 'paused', 'finalizing'), true)),
        'last_error'       => is_array($err) && $err ? $err : null,
        'last_email'       => array(
            'status'  => (string) devdlink_get_setting('email_last_status', ''),
            'scan_id' => (int) devdlink_get_int('email_last_scan_id', 0),
            'at'      => devdlink_get_int('email_last_at', 0) ? gmdate('c', devdlink_get_int('email_last_at', 0)) : '',
        ),
        'scheduled_rescan'  => (string) devdlink_get_setting('scheduled_rescan', 'off'),
        'email_new_broken'  => (bool) devdlink_get_int('email_new_broken', 0),
        'account_connected' => devdlink_ability_account_connected(),
    );
}

function devdlink_ability_get_links($input = array())
{
    $input = is_array($input) ? $input : array();
    $valid = array('broken', 'redirects', 'timeouts', 'blocked', 'internal', 'external', 'all');
    $filter = isset($input['filter']) ? sanitize_key((string) $input['filter']) : 'broken';
    $filter = in_array($filter, $valid, true) ? $filter : 'broken';
    $page = isset($input['page']) ? max(1, (int) $input['page']) : 1;
    $per = isset($input['per_page']) ? max(1, min(200, (int) $input['per_page'])) : 50;
    $search = isset($input['search']) ? sanitize_text_field((string) $input['search']) : '';
    $scan_id = isset($input['scan_id']) ? (int) $input['scan_id'] : 0;
    if ($scan_id < 1) {
        $scan_id = devdlink_get_int('last_scan_id', 0);
    }
    $res = devdlink_links_query(array('scan_id' => $scan_id, 'filter' => $filter, 'page' => $page, 'per_page' => $per, 'search' => $search));
    $ids = array();
    foreach ($res['rows'] as $r) {
        $ids[] = (int) $r['id'];
    }
    $sources_map = devdlink_rest_link_sources($ids);
    $items = array();
    foreach ($res['rows'] as $r) {
        $lid = (int) $r['id'];
        $items[] = devdlink_rest_format_link($r, isset($sources_map[$lid]) ? $sources_map[$lid] : array());
    }
    return array(
        'scan_id' => $scan_id, 'filter' => $filter, 'total' => (int) $res['total'], 'page' => $page,
        'pages'   => (int) ceil($res['total'] / $per), 'counts' => devdlink_links_counts($scan_id), 'items' => $items,
    );
}

function devdlink_ability_get_link($input = array())
{
    $input = is_array($input) ? $input : array();
    $row = devdlink_rest_get_link_row(isset($input['link_id']) ? (int) $input['link_id'] : 0);
    if (!$row) {
        return new WP_Error('devdlink_not_found', __('Link not found.', 'devdome-link-monitor'));
    }
    return devdlink_rest_format_link($row);
}

function devdlink_ability_get_404s($input = array())
{
    if (!devdlink_rest_load_404_helpers()) {
        return new WP_Error('devdlink_unavailable', __('The 404 log is unavailable.', 'devdome-link-monitor'));
    }
    $input = is_array($input) ? $input : array();
    $page = isset($input['page']) ? max(1, (int) $input['page']) : 1;
    $per = isset($input['per_page']) ? max(1, min(200, (int) $input['per_page'])) : 50;
    $orderby = isset($input['orderby']) ? sanitize_key((string) $input['orderby']) : 'human_hits';
    $orderby = in_array($orderby, array('human_hits', 'bot_hits', 'last_seen'), true) ? $orderby : 'human_hits';
    $ign = isset($input['ignored']) ? (string) $input['ignored'] : 'exclude';
    $res = devdlink_404_rows(array(
        'page' => $page, 'per_page' => $per,
        'humans_only'  => !empty($input['humans_only']) ? 1 : 0,
        'show_ignored' => $ign === 'include' ? 1 : 0,
        'ignored_only' => $ign === 'only' ? 1 : 0,
        'orderby' => $orderby,
        'order'   => (isset($input['order']) && strtolower((string) $input['order']) === 'asc') ? 'ASC' : 'DESC',
        'search'  => isset($input['search']) ? sanitize_text_field((string) $input['search']) : '',
    ));
    $items = array_map('devdlink_ability_format_404', (array) $res['rows']);
    $total = isset($res['total']) ? (int) $res['total'] : 0;
    return array(
        'total' => $total, 'page' => $page, 'pages' => (int) ceil($total / $per),
        'counts' => devdlink_404_pill_counts(), 'classifier' => devdlink_classifier_name(), 'items' => $items,
    );
}

function devdlink_ability_404_suggest($input = array())
{
    global $wpdb;
    if (!devdlink_rest_load_404_helpers() || !function_exists('devdlink_suggest_redirect')) {
        return new WP_Error('devdlink_unavailable', __('The 404 log is unavailable.', 'devdome-link-monitor'));
    }
    $input = is_array($input) ? $input : array();
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $table = $wpdb->prefix . 'devdlink_404s';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table, prepared.
    $path = $wpdb->get_var($wpdb->prepare("SELECT path FROM {$table} WHERE id = %d", $id));
    if ($path === null) {
        return new WP_Error('devdlink_not_found', __('404 entry not found.', 'devdome-link-monitor'));
    }
    $sug = devdlink_suggest_redirect((string) $path);
    return array('id' => $id, 'path' => (string) $path, 'suggestion' => is_array($sug) ? array(
        'url' => (string) $sug['url'], 'label' => (string) $sug['label'], 'rm_url' => (string) $sug['rm_url'], 'htaccess' => (string) $sug['htaccess'],
    ) : null);
}

function devdlink_ability_get_progress($input = array())
{
    return devdlink_job_progress();
}

function devdlink_ability_get_history($input = array())
{
    global $wpdb;
    $input = is_array($input) ? $input : array();
    $limit = isset($input['limit']) ? max(1, min(50, (int) $input['limit'])) : 12;
    $table = $wpdb->prefix . 'devdlink_scans';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table, prepared.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit), ARRAY_A);
    $items = array();
    foreach ((array) $rows as $r) {
        $items[] = array(
            'id'          => (int) $r['id'],
            'type'        => isset($r['scan_type']) ? (string) $r['scan_type'] : 'full',
            'status'      => (string) $r['status'],
            'started_at'  => isset($r['started_at']) ? (string) $r['started_at'] : '',
            'finished_at' => isset($r['finished_at']) ? (string) $r['finished_at'] : (isset($r['completed_at']) ? (string) $r['completed_at'] : ''),
            'total_posts' => isset($r['total_posts']) ? (int) $r['total_posts'] : 0,
            'total_links' => isset($r['total_links']) ? (int) $r['total_links'] : 0,
            'ok'          => isset($r['ok_count']) ? (int) $r['ok_count'] : 0,
            'broken'      => isset($r['broken_count']) ? (int) $r['broken_count'] : 0,
            'redirect'    => isset($r['redirect_count']) ? (int) $r['redirect_count'] : 0,
            'timeout'     => isset($r['timeout_count']) ? (int) $r['timeout_count'] : 0,
            'blocked'     => isset($r['blocked_count']) ? (int) $r['blocked_count'] : 0,
            'new_broken'  => isset($r['new_broken_count']) ? (int) $r['new_broken_count'] : 0,
        );
    }
    return array('items' => $items);
}

function devdlink_ability_get_settings($input = array())
{
    $ex = devdlink_get_array('excluded_domains', array());
    return array(
        'check_timeout'     => (int) devdlink_get_int('check_timeout', 10),
        'scan_chunk_size'   => (int) devdlink_get_int('scan_chunk_size', 50),
        'check_batch_size'  => (int) devdlink_get_int('check_batch_size', 15),
        'user_agent'        => (string) devdlink_get_setting('user_agent', ''),
        'excluded_domains'  => array_values(array_map('strval', is_array($ex) ? $ex : array())),
        'purge_days'        => (int) devdlink_get_int('purge_days', 90),
        'referrer_mode'     => (string) devdlink_get_setting('referrer_mode', 'origin_path'),
        'store_user_agent'  => (bool) devdlink_get_int('store_user_agent', 1),
        'scheduled_rescan'  => (string) devdlink_get_setting('scheduled_rescan', 'off'),
        'email_new_broken'  => (bool) devdlink_get_int('email_new_broken', 0),
        'classifier'        => devdlink_classifier_name(),
        'account_connected' => devdlink_ability_account_connected(),
    );
}

function devdlink_ability_export_links($input = array())
{
    global $wpdb;
    $scan_id = devdlink_get_int('last_scan_id', 0);
    $table = $wpdb->prefix . 'devdlink_links';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table, prepared.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE scan_id = %d AND status IN ('broken','redirect','timeout','blocked') ORDER BY id ASC LIMIT 5000", $scan_id), ARRAY_A);
    $ids = array_map(function ($r) { return (int) $r['id']; }, (array) $rows);
    $sources = devdlink_rest_link_sources($ids);
    $out = array();
    foreach ((array) $rows as $r) {
        $lid = (int) $r['id'];
        $titles = array();
        foreach (isset($sources[$lid]) ? $sources[$lid] : array() as $s) {
            $titles[] = $s['title'];
        }
        $out[] = array(
            'url' => (string) $r['url'], 'status' => devdlink_status_label((string) $r['status'], ''), 'reason' => devdlink_error_class_label((string) $r['error_class']),
            'http_code' => (int) $r['http_code'], 'anchor' => (string) $r['anchor_text'], 'found_in' => implode(' | ', array_unique($titles)),
            'redirect_url' => (string) $r['redirect_url'], 'checked_at' => (string) $r['checked_at'],
        );
    }
    return array('total' => count($out), 'columns' => array('url', 'status', 'reason', 'http_code', 'anchor', 'found_in', 'redirect_url', 'checked_at'), 'rows' => $out);
}

function devdlink_ability_export_404s($input = array())
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_404s';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
    $rows = $wpdb->get_results("SELECT path, human_hits, bot_hits, first_seen, last_seen, last_referrer, ignored FROM {$table} ORDER BY id ASC LIMIT 5000", ARRAY_A);
    $out = array();
    foreach ((array) $rows as $r) {
        $out[] = array(
            'path' => (string) $r['path'], 'human_hits' => (int) $r['human_hits'], 'bot_hits' => (int) $r['bot_hits'],
            'first_seen' => (string) $r['first_seen'], 'last_seen' => (string) $r['last_seen'],
            'last_referrer' => devdlink_redact_secrets((string) $r['last_referrer']), 'ignored' => (int) $r['ignored'],
        );
    }
    return array('total' => count($out), 'columns' => array('path', 'human_hits', 'bot_hits', 'first_seen', 'last_seen', 'last_referrer', 'ignored'), 'rows' => $out);
}

function devdlink_ability_get_logs($input = array())
{
    $input = is_array($input) ? $input : array();
    $which = isset($input['log']) ? (string) $input['log'] : 'both';
    $limit = isset($input['limit']) ? max(1, min(100, (int) $input['limit'])) : 25;
    $out = array('errors' => array(), 'mutations' => array());
    if ($which !== 'mutations') {
        $log = devdlink_get_array('error_log', array());
        $out['errors'] = array_slice(array_reverse(is_array($log) ? array_values($log) : array()), 0, $limit);
    }
    if ($which !== 'errors') {
        $log = devdlink_get_array('mutation_log', array());
        $out['mutations'] = array_slice(array_reverse(is_array($log) ? array_values($log) : array()), 0, $limit);
    }
    return $out;
}

function devdlink_ability_run_scan($input = array())
{
    $input = is_array($input) ? $input : array();
    $mode = isset($input['mode']) ? sanitize_key((string) $input['mode']) : 'full';
    $job = devdlink_start_job($mode === 'recheck' ? 'recheck' : 'scan');
    if (is_wp_error($job)) {
        return $job;
    }
    return array_merge(array('started' => true), devdlink_job_progress());
}

function devdlink_ability_control_scan($input = array())
{
    $input = is_array($input) ? $input : array();
    $action = isset($input['action']) ? sanitize_key((string) $input['action']) : '';
    if ($action === 'pause') {
        if (!devdlink_pause_job()) {
            return new WP_Error('devdlink_db_write', __('The pause could not be saved.', 'devdome-link-monitor'));
        }
    } elseif ($action === 'resume') {
        if (!devdlink_resume_job()) {
            return new WP_Error('devdlink_db_write', __('The resume could not be saved.', 'devdome-link-monitor'));
        }
    } elseif ($action === 'cancel') {
        $r = devdlink_cancel_job();
        if (is_wp_error($r)) {
            return $r;
        }
    } else {
        return new WP_Error('devdlink_bad_input', __('action must be pause, resume or cancel.', 'devdome-link-monitor'));
    }
    return devdlink_job_progress();
}

function devdlink_ability_recheck_link($input = array())
{
    $input = is_array($input) ? $input : array();
    $link_id = isset($input['link_id']) ? (int) $input['link_id'] : 0;
    if ($link_id < 1) {
        return new WP_Error('devdlink_bad_input', __('link_id is required.', 'devdome-link-monitor'));
    }
    $row = devdlink_recheck_single($link_id);
    if (is_wp_error($row)) {
        return $row;
    }
    return devdlink_rest_format_link($row);
}

function devdlink_ability_edit_url($input = array())
{
    $input = is_array($input) ? $input : array();
    $link_id = isset($input['link_id']) ? (int) $input['link_id'] : 0;
    $new_url = isset($input['new_url']) ? esc_url_raw(trim((string) $input['new_url'])) : '';
    if ($link_id < 1) {
        return new WP_Error('devdlink_bad_input', __('link_id is required.', 'devdome-link-monitor'));
    }
    if ($new_url === '' || !preg_match('#^https?://#i', $new_url)) {
        return new WP_Error('devdlink_bad_url', __('new_url must start with http:// or https://.', 'devdome-link-monitor'));
    }
    $res = devdlink_edit_link_url($link_id, $new_url);
    if (is_wp_error($res)) {
        return $res;
    }
    $row = devdlink_rest_get_link_row($link_id);
    return array('updated_posts' => (int) (isset($res['updated_posts']) ? $res['updated_posts'] : 0), 'link' => $row ? devdlink_rest_format_link($row) : null);
}

function devdlink_ability_unlink($input = array())
{
    $input = is_array($input) ? $input : array();
    $link_id = isset($input['link_id']) ? (int) $input['link_id'] : 0;
    if ($link_id < 1) {
        return new WP_Error('devdlink_bad_input', __('link_id is required.', 'devdome-link-monitor'));
    }
    $res = devdlink_unlink($link_id);
    if (is_wp_error($res)) {
        return $res;
    }
    return array('updated_posts' => (int) (is_array($res) && isset($res['updated_posts']) ? $res['updated_posts'] : (is_numeric($res) ? $res : 0)));
}

function devdlink_ability_dismiss($input = array())
{
    global $wpdb;
    $input = is_array($input) ? $input : array();
    $link_id = isset($input['link_id']) ? (int) $input['link_id'] : 0;
    if ($link_id < 1 || !devdlink_rest_get_link_row($link_id)) {
        return new WP_Error('devdlink_not_found', __('Link not found.', 'devdome-link-monitor'));
    }
    $table = $wpdb->prefix . 'devdlink_links';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
    $ok = $wpdb->update($table, array('status' => 'dismissed'), array('id' => $link_id), array('%s'), array('%d'));
    if ($ok === false) {
        return new WP_Error('devdlink_db_write', __('The link could not be dismissed.', 'devdome-link-monitor'));
    }
    devdlink_refresh_summary();
    return array('link_id' => $link_id, 'status' => 'dismissed');
}

function devdlink_ability_404_ignore($input = array())
{
    global $wpdb;
    if (!devdlink_rest_load_404_helpers()) {
        return new WP_Error('devdlink_unavailable', __('The 404 log is unavailable.', 'devdome-link-monitor'));
    }
    $input = is_array($input) ? $input : array();
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $table = $wpdb->prefix . 'devdlink_404s';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table, prepared.
    if ($id < 1 || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id))) {
        return new WP_Error('devdlink_not_found', __('404 entry not found.', 'devdome-link-monitor'));
    }
    $ignored = !empty($input['ignored']) ? 1 : 0;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
    if ($wpdb->update($table, array('ignored' => $ignored), array('id' => $id), array('%d'), array('%d')) === false) {
        return new WP_Error('devdlink_db_write', __('The change could not be saved.', 'devdome-link-monitor'));
    }
    devdlink_refresh_summary();
    return array('id' => $id, 'ignored' => (bool) $ignored);
}

function devdlink_ability_404_delete($input = array())
{
    global $wpdb;
    if (!devdlink_rest_load_404_helpers()) {
        return new WP_Error('devdlink_unavailable', __('The 404 log is unavailable.', 'devdome-link-monitor'));
    }
    $input = is_array($input) ? $input : array();
    $id = isset($input['id']) ? (int) $input['id'] : 0;
    $table = $wpdb->prefix . 'devdlink_404s';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table, prepared.
    if ($id < 1 || !$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE id = %d", $id))) {
        return new WP_Error('devdlink_not_found', __('404 entry not found.', 'devdome-link-monitor'));
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
    if ($wpdb->delete($table, array('id' => $id), array('%d')) === false) {
        return new WP_Error('devdlink_db_write', __('The entry could not be deleted.', 'devdome-link-monitor'));
    }
    if (function_exists('devdlink_record_mutation')) {
        devdlink_record_mutation('delete_404', $id, array(), '404 row #' . $id, '', 'ok');
    }
    devdlink_refresh_summary();
    return array('deleted' => true, 'id' => $id);
}

function devdlink_ability_purge_404s($input = array())
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_404s';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
    $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    devdlink_purge_404s();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
    $after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    return array('purged' => true, 'rows_before' => $before, 'rows_after' => $after);
}

function devdlink_ability_update_settings($input = array())
{
    $input = is_array($input) ? $input : array();
    $has = function ($k) use ($input) { return array_key_exists($k, $input); };
    if ($has('check_timeout')) {
        devdlink_update_setting('check_timeout', max(3, min(30, (int) $input['check_timeout'])));
    }
    if ($has('scan_chunk_size')) {
        devdlink_update_setting('scan_chunk_size', max(10, min(500, (int) $input['scan_chunk_size'])));
    }
    if ($has('check_batch_size')) {
        devdlink_update_setting('check_batch_size', max(3, min(50, (int) $input['check_batch_size'])));
    }
    if ($has('user_agent')) {
        $ua = substr(sanitize_text_field((string) $input['user_agent']), 0, 255);
        if ($ua === '') {
            $ua = 'Mozilla/5.0 (compatible; DevDomeLinkMonitor/' . DEVDLINK_VERSION . '; +https://devdome.com)';
        }
        devdlink_update_setting('user_agent', $ua);
    }
    if ($has('excluded_domains')) {
        $hosts = array();
        foreach ((array) $input['excluded_domains'] as $line) {
            $line = strtolower(trim((string) $line));
            if ($line === '') {
                continue;
            }
            if (preg_match('#^[a-z][a-z0-9+.-]*://#', $line)) {
                $h = wp_parse_url($line, PHP_URL_HOST);
                $line = $h ? $h : '';
            }
            $line = sanitize_text_field(trim($line, " \t/"));
            if ($line !== '' && !in_array($line, $hosts, true)) {
                $hosts[] = $line;
            }
        }
        devdlink_update_setting('excluded_domains', $hosts);
    }
    if ($has('purge_days')) {
        $d = (int) $input['purge_days'];
        if (!in_array($d, array(30, 90, 180), true)) {
            return new WP_Error('devdlink_bad_input', __('purge_days must be 30, 90 or 180.', 'devdome-link-monitor'));
        }
        devdlink_update_setting('purge_days', $d);
    }
    if ($has('referrer_mode')) {
        $m = sanitize_key((string) $input['referrer_mode']);
        if (!in_array($m, array('origin_path', 'origin', 'none'), true)) {
            return new WP_Error('devdlink_bad_input', __('referrer_mode must be origin_path, origin or none.', 'devdome-link-monitor'));
        }
        devdlink_update_setting('referrer_mode', $m);
    }
    if ($has('store_user_agent')) {
        devdlink_update_setting('store_user_agent', !empty($input['store_user_agent']) ? 1 : 0);
    }
    if ($has('scheduled_rescan')) {
        $s = sanitize_key((string) $input['scheduled_rescan']);
        if (!in_array($s, array('off', 'weekly', 'monthly'), true)) {
            return new WP_Error('devdlink_bad_input', __('scheduled_rescan must be off, weekly or monthly.', 'devdome-link-monitor'));
        }
        devdlink_update_setting('scheduled_rescan', $s);
    }
    if ($has('email_new_broken')) {
        $want = !empty($input['email_new_broken']);
        if ($want && !devdlink_ability_account_connected()) {
            return new WP_Error('devdlink_needs_account', __('email_new_broken needs a connected DevDome account with an email address.', 'devdome-link-monitor'));
        }
        devdlink_update_setting('email_new_broken', $want ? 1 : 0);
    }
    return array_merge(array('updated' => true), array_diff_key(devdlink_ability_get_settings(), array('classifier' => 1, 'account_connected' => 1)));
}

function devdlink_ability_clear_error($input = array())
{
    devdlink_clear_last_error();
    return array('cleared' => true);
}

function devdlink_ability_refresh_summary($input = array())
{
    $ok = devdlink_refresh_summary();
    return array_merge(array('refreshed' => $ok !== false), devdlink_ability_get_summary());
}

/**
 * Official WordPress MCP Adapter: list our abilities as direct tools on its default server
 * (next to its discover / execute meta-tools). Harmless when the adapter is not installed.
 */
add_filter('mcp_adapter_default_server_config', 'devdlink_mcp_default_server_tools');
function devdlink_mcp_default_server_tools($config)
{
    if (!is_array($config)) {
        return $config;
    }
    $tools = isset($config['tools']) && is_array($config['tools']) ? $config['tools'] : array();
    $config['tools'] = array_values(array_unique(array_merge($tools, devdlink_ability_ids())));
    return $config;
}
