<?php
/**
 * WordPress Abilities API layer (WordPress 6.9+): the plugin's read and scan actions exposed
 * as typed, discoverable abilities so AI agents and MCP clients (through the official
 * WordPress MCP Adapter) can use Link Monitor without the wp-admin UI.
 *
 * Nothing here is new logic: every ability calls the same functions the REST controller and
 * the admin page use, under the same capability contexts (view / data / scan). On WordPress
 * older than 6.9 the API does not exist and this file registers nothing.
 */

defined('ABSPATH') || exit;

/** The ability ids this plugin registers, in the order they are listed to clients. */
function devdlink_ability_ids()
{
    return array(
        'devdome-link-monitor/get-link-summary',
        'devdome-link-monitor/get-broken-links',
        'devdome-link-monitor/get-404s',
        'devdome-link-monitor/get-scan-progress',
        'devdome-link-monitor/run-link-scan',
        'devdome-link-monitor/recheck-link',
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
        'description' => __('Broken link checker and 404 monitor for WordPress: read broken, redirected and unverified links, read the 404 log, run and follow link scans.', 'devdome-link-monitor'),
    ));
}

/** Permission callbacks: the same capability contexts as the REST routes. */
function devdlink_ability_can_view()
{
    return current_user_can(devdlink_capability('view'));
}
function devdlink_ability_can_data()
{
    return current_user_can(devdlink_capability('data'));
}
function devdlink_ability_can_scan()
{
    return current_user_can(devdlink_capability('scan'));
}

/** MCP-facing meta shared by every ability: public, in REST, with behaviour hints. */
function devdlink_ability_meta($readonly)
{
    return array(
        'public'       => true,
        'show_in_rest' => true,
        'annotations'  => array(
            'readonly'    => (bool) $readonly,
            'destructive' => false,
            'idempotent'  => (bool) $readonly,
        ),
        'mcp'          => array('type' => 'tool'),
    );
}

add_action('wp_abilities_api_init', 'devdlink_register_abilities');
function devdlink_register_abilities()
{
    if (!function_exists('wp_register_ability')) {
        return;
    }

    $paging = array(
        'page'     => array('type' => 'integer', 'minimum' => 1, 'default' => 1, 'description' => 'Result page, starting at 1.'),
        'per_page' => array('type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50, 'description' => 'Rows per page, 1 to 200.'),
    );

    wp_register_ability('devdome-link-monitor/get-link-summary', array(
        'label'               => __('Get link health summary', 'devdome-link-monitor'),
        'description'         => __('Get the WordPress site link health summary from DevDome Link Monitor: link health score, totals of ok, broken, redirected, timed-out and blocked links from the last completed link scan, when that scan ran, and 404 monitor totals (missing-page paths, human hits, bot hits). Read only, fast, no scan is started.', 'devdome-link-monitor'),
        'category'            => 'devdome-link-monitor',
        'input_schema'        => array('type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false),
        'output_schema'       => array(
            'type'       => 'object',
            'properties' => array(
                'health_score'   => array('type' => 'number', 'description' => 'Link health 0 to 100 (100 = no broken links).'),
                'last_scan_at'   => array('type' => 'string', 'description' => 'ISO 8601 UTC time of the last completed link scan, empty if never scanned.'),
                'links_total'    => array('type' => 'integer'),
                'links_ok'       => array('type' => 'integer'),
                'links_broken'   => array('type' => 'integer'),
                'links_redirect' => array('type' => 'integer'),
                'links_timeout'  => array('type' => 'integer', 'description' => 'Unverified links: timeouts, DNS or TLS errors, never called broken.'),
                'links_blocked'  => array('type' => 'integer', 'description' => 'Could not verify: 401, 403, 429 or anti-bot answers.'),
                'fof_paths'      => array('type' => 'integer', 'description' => 'Distinct 404 paths in the 404 log.'),
                'fof_human_hits' => array('type' => 'integer'),
                'fof_bot_hits'   => array('type' => 'integer'),
                'scan_running'   => array('type' => 'boolean'),
            ),
        ),
        'execute_callback'    => 'devdlink_ability_get_summary',
        'permission_callback' => 'devdlink_ability_can_view',
        'meta'                => devdlink_ability_meta(true),
    ));

    wp_register_ability('devdome-link-monitor/get-broken-links', array(
        'label'               => __('Get broken links', 'devdome-link-monitor'),
        'description'         => __('Find broken links on this WordPress site. Returns links from the last completed link scan with their HTTP status, error, the pages (posts) they appear on and the anchor text. Default filter is broken (confirmed dead after two failed checks); set filter to redirects, timeouts (unverified), blocked (could not verify), internal, external or all to read other buckets. Read only, does not start a scan.', 'devdome-link-monitor'),
        'category'            => 'devdome-link-monitor',
        'input_schema'        => array(
            'type'                 => 'object',
            'properties'           => array_merge(array(
                'filter' => array('type' => 'string', 'enum' => array('broken', 'redirects', 'timeouts', 'blocked', 'internal', 'external', 'all'), 'default' => 'broken', 'description' => 'Which links to return.'),
                'search' => array('type' => 'string', 'default' => '', 'description' => 'Optional substring to match in the URL or anchor text.'),
            ), $paging),
            'additionalProperties' => false,
        ),
        'output_schema'       => array(
            'type'       => 'object',
            'properties' => array(
                'scan_id' => array('type' => 'integer'),
                'filter'  => array('type' => 'string'),
                'total'   => array('type' => 'integer', 'description' => 'Rows matching the filter.'),
                'page'    => array('type' => 'integer'),
                'pages'   => array('type' => 'integer'),
                'counts'  => array('type' => 'object', 'description' => 'Totals per bucket: all, broken, redirects, timeouts, blocked, internal, external.'),
                'items'   => array('type' => 'array', 'description' => 'Links: id, url, status, http_code, error_message, redirect_url, anchor, internal, sources (post_id, title, view_url, edit_url).'),
            ),
        ),
        'execute_callback'    => 'devdlink_ability_get_links',
        'permission_callback' => 'devdlink_ability_can_data',
        'meta'                => devdlink_ability_meta(true),
    ));

    wp_register_ability('devdome-link-monitor/get-404s', array(
        'label'               => __('Get 404 errors', 'devdome-link-monitor'),
        'description'         => __('Get the 404 error log of this WordPress site: every missing-page path visitors or bots requested, with human hits and bot hits kept apart, last referrer, first and last seen. Set humans_only to true to see only paths real people hit. Read only.', 'devdome-link-monitor'),
        'category'            => 'devdome-link-monitor',
        'input_schema'        => array(
            'type'                 => 'object',
            'properties'           => array_merge(array(
                'humans_only' => array('type' => 'boolean', 'default' => false, 'description' => 'Only paths with at least one human hit.'),
                'orderby'     => array('type' => 'string', 'enum' => array('human_hits', 'bot_hits', 'last_seen'), 'default' => 'human_hits'),
                'search'      => array('type' => 'string', 'default' => '', 'description' => 'Optional substring to match in the path.'),
            ), $paging),
            'additionalProperties' => false,
        ),
        'output_schema'       => array(
            'type'       => 'object',
            'properties' => array(
                'total'  => array('type' => 'integer'),
                'page'   => array('type' => 'integer'),
                'pages'  => array('type' => 'integer'),
                'counts' => array('type' => 'object', 'description' => 'paths, human, bot, ignored totals.'),
                'items'  => array('type' => 'array', 'description' => '404 rows: id, path, human_hits, bot_hits, referrer, first_seen, last_seen.'),
            ),
        ),
        'execute_callback'    => 'devdlink_ability_get_404s',
        'permission_callback' => 'devdlink_ability_can_data',
        'meta'                => devdlink_ability_meta(true),
    ));

    wp_register_ability('devdome-link-monitor/get-scan-progress', array(
        'label'               => __('Get link scan progress', 'devdome-link-monitor'),
        'description'         => __('Check whether a link scan is running on this WordPress site and how far it is: phase, percent, processed and total counts, estimated time left. Read only.', 'devdome-link-monitor'),
        'category'            => 'devdome-link-monitor',
        'input_schema'        => array('type' => 'object', 'properties' => new stdClass(), 'additionalProperties' => false),
        'output_schema'       => array('type' => 'object', 'properties' => array(
            'active'  => array('type' => 'boolean'),
            'status'  => array('type' => 'string', 'description' => 'idle, running, paused, finalizing or completed.'),
            'phase'   => array('type' => 'string'),
            'percent' => array('type' => 'number'),
        )),
        'execute_callback'    => 'devdlink_ability_get_progress',
        'permission_callback' => 'devdlink_ability_can_view',
        'meta'                => devdlink_ability_meta(true),
    ));

    wp_register_ability('devdome-link-monitor/run-link-scan', array(
        'label'               => __('Run a link scan', 'devdome-link-monitor'),
        'description'         => __('Start a broken link scan of this WordPress site. mode full (default) extracts every link from all published content and checks each one; mode recheck re-verifies only the links that were broken, unverified or blocked in the last scan. The scan runs in the background in small slices; use get-scan-progress to follow it and get-broken-links when it completes. Fails with a clear message if a scan is already running. Sends HTTP requests to the linked sites, changes no content.', 'devdome-link-monitor'),
        'category'            => 'devdome-link-monitor',
        'input_schema'        => array(
            'type'                 => 'object',
            'properties'           => array(
                'mode' => array('type' => 'string', 'enum' => array('full', 'recheck'), 'default' => 'full'),
            ),
            'additionalProperties' => false,
        ),
        'output_schema'       => array('type' => 'object', 'properties' => array(
            'started' => array('type' => 'boolean'),
            'status'  => array('type' => 'string'),
            'phase'   => array('type' => 'string'),
            'percent' => array('type' => 'number'),
        )),
        'execute_callback'    => 'devdlink_ability_run_scan',
        'permission_callback' => 'devdlink_ability_can_scan',
        'meta'                => devdlink_ability_meta(false),
    ));

    wp_register_ability('devdome-link-monitor/recheck-link', array(
        'label'               => __('Recheck one link', 'devdome-link-monitor'),
        'description'         => __('Check one link again right now by its link id (from get-broken-links) and return its updated status. Use it to confirm whether a link reported broken or unverified is really dead. Sends one HTTP request to the link, changes no content.', 'devdome-link-monitor'),
        'category'            => 'devdome-link-monitor',
        'input_schema'        => array(
            'type'                 => 'object',
            'properties'           => array(
                'link_id' => array('type' => 'integer', 'minimum' => 1, 'description' => 'The id of the link row.'),
            ),
            'required'             => array('link_id'),
            'additionalProperties' => false,
        ),
        'output_schema'       => array('type' => 'object', 'description' => 'The updated link: id, url, status, http_code, error_message, redirect_url, checked_at, sources.'),
        'execute_callback'    => 'devdlink_ability_recheck_link',
        'permission_callback' => 'devdlink_ability_can_scan',
        'meta'                => devdlink_ability_meta(false),
    ));
}

/* ------------------------------- callbacks ------------------------------- */

function devdlink_ability_get_summary($input = array())
{
    $s = devdlink_hub_summary();
    $job = function_exists('devdlink_get_job') ? devdlink_get_job() : null;
    return array(
        'health_score'   => (float) $s['health_score'],
        'last_scan_at'   => $s['last_scan_at'] ? gmdate('c', (int) $s['last_scan_at']) : '',
        'links_total'    => (int) $s['links_total'],
        'links_ok'       => (int) $s['links_ok'],
        'links_broken'   => (int) $s['links_broken'],
        'links_redirect' => (int) $s['links_redirect'],
        'links_timeout'  => (int) $s['links_timeout'],
        'links_blocked'  => (int) $s['links_blocked'],
        'fof_paths'      => (int) $s['fof_paths'],
        'fof_human_hits' => (int) $s['fof_human_hits'],
        'fof_bot_hits'   => (int) $s['fof_bot_hits'],
        'scan_running'   => (bool) ($job && in_array($job['status'], array('running', 'finalizing'), true)),
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
    $scan_id = devdlink_get_int('last_scan_id', 0);

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
        'scan_id' => $scan_id,
        'filter'  => $filter,
        'total'   => (int) $res['total'],
        'page'    => $page,
        'pages'   => (int) ceil($res['total'] / $per),
        'counts'  => devdlink_links_counts($scan_id),
        'items'   => $items,
    );
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

    $res = devdlink_404_rows(array(
        'page'         => $page,
        'per_page'     => $per,
        'humans_only'  => !empty($input['humans_only']) ? 1 : 0,
        'show_ignored' => 0,
        'ignored_only' => 0,
        'orderby'      => $orderby,
        'order'        => 'DESC',
        'search'       => isset($input['search']) ? sanitize_text_field((string) $input['search']) : '',
    ));
    $items = array();
    foreach ((array) $res['rows'] as $r) {
        $r = (array) $r;
        $items[] = array(
            'id'         => isset($r['id']) ? (int) $r['id'] : 0,
            'path'       => isset($r['path']) ? (string) $r['path'] : '',
            'human_hits' => isset($r['human_hits']) ? (int) $r['human_hits'] : 0,
            'bot_hits'   => isset($r['bot_hits']) ? (int) $r['bot_hits'] : 0,
            'referrer'   => isset($r['last_referrer']) ? devdlink_redact_secrets((string) $r['last_referrer']) : '',
            'first_seen' => isset($r['first_seen']) ? (string) $r['first_seen'] : '',
            'last_seen'  => isset($r['last_seen']) ? (string) $r['last_seen'] : '',
        );
    }
    $total = isset($res['total']) ? (int) $res['total'] : 0;
    return array(
        'total'  => $total,
        'page'   => $page,
        'pages'  => (int) ceil($total / $per),
        'counts' => devdlink_404_counts(),
        'items'  => $items,
    );
}

function devdlink_ability_get_progress($input = array())
{
    return devdlink_job_progress();
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

/**
 * Official WordPress MCP Adapter: list our abilities as direct tools on its default server
 * (next to its discover / execute meta-tools), so an AI client sees them in tools/list with
 * their full schemas. Harmless when the adapter is not installed: the filter never runs.
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
