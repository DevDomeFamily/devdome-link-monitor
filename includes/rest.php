<?php
/**
 * REST controller — namespace devdlink/v1. Powers the async admin UI:
 * start-scan, scan-progress (poller = driver), job-control, the filtered links review
 * list + row actions (recheck / edit / unlink / dismiss), and the 404 log list/actions.
 * Every route: permission_callback = capability check, nonce via X-WP-Nonce (cookie auth),
 * all input sanitized + allowlisted, all SQL prepared.
 */

defined('ABSPATH') || exit;

add_action('rest_api_init', 'devdlink_register_routes');

/** A positive-integer id argument. */
function devdlink_rest_arg_id($required = true)
{
    return array(
        'type'              => 'integer',
        'required'          => (bool) $required,
        'minimum'           => 1,
        'sanitize_callback' => 'absint',
        'validate_callback' => 'rest_validate_request_arg',
    );
}

function devdlink_register_routes()
{
    $ns = 'devdlink/v1';

    // Every route: one explicit method, a typed/enumerated argument schema, and the plugin
    // capability CONTEXT it belongs to. No GET route runs work or sends a request.
    register_rest_route($ns, '/start-scan', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_start_scan',
        'permission_callback' => 'devdlink_rest_permission_scan',
        'args'                => array(
            'mode' => array(
                'type'              => 'string',
                'default'           => 'full',
                'enum'              => array('full', 'recheck'),
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ),
        ),
    ));

    register_rest_route($ns, '/scan-progress', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'devdlink_rest_progress',
        'permission_callback' => 'devdlink_rest_permission_view',
    ));

    // Advancing the queue is a WRITE. The audited build did it from GET /scan-progress, so a
    // browser poll (or anything replaying the URL) drove the worker.
    register_rest_route($ns, '/scan-tick', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_scan_tick',
        'permission_callback' => 'devdlink_rest_permission_tick',
    ));

    register_rest_route($ns, '/job-control', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_job_control',
        'permission_callback' => 'devdlink_rest_permission_scan',
        'args'                => array(
            'action' => array(
                'type'              => 'string',
                'required'          => true,
                'enum'              => array('pause', 'resume', 'cancel'),
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ),
        ),
    ));

    register_rest_route($ns, '/links', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'devdlink_rest_links',
        'permission_callback' => 'devdlink_rest_permission_view',
        'args'                => array(
            'scan_id'  => devdlink_rest_arg_id(false),
            'page'     => array('type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 100000, 'sanitize_callback' => 'absint', 'validate_callback' => 'rest_validate_request_arg'),
            'per_page' => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200, 'sanitize_callback' => 'absint', 'validate_callback' => 'rest_validate_request_arg'),
            'filter'   => array(
                'type'              => 'string',
                'default'           => 'all',
                'enum'              => array('all', 'broken', 'redirects', 'timeouts', 'blocked', 'internal', 'external'),
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ),
            'search'   => array('type' => 'string', 'default' => '', 'maxLength' => 191, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => 'rest_validate_request_arg'),
        ),
    ));

    register_rest_route($ns, '/recheck-link', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_recheck_link',
        'permission_callback' => 'devdlink_rest_permission_scan',
        'args'                => array('link_id' => devdlink_rest_arg_id()),
    ));

    register_rest_route($ns, '/edit-url', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_edit_url',
        'permission_callback' => 'devdlink_rest_permission_mutate',
        'args'                => array(
            'link_id' => devdlink_rest_arg_id(),
            'new_url' => array(
                'type'              => 'string',
                'required'          => true,
                'maxLength'         => 2000,
                'sanitize_callback' => 'esc_url_raw',
                'validate_callback' => 'rest_validate_request_arg',
            ),
        ),
    ));

    register_rest_route($ns, '/unlink', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_unlink',
        'permission_callback' => 'devdlink_rest_permission_mutate',
        'args'                => array('link_id' => devdlink_rest_arg_id()),
    ));

    register_rest_route($ns, '/dismiss', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_dismiss',
        'permission_callback' => 'devdlink_rest_permission_mutate',
        'args'                => array('link_id' => devdlink_rest_arg_id()),
    ));

    register_rest_route($ns, '/404s', array(
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'devdlink_rest_404s',
        'permission_callback' => 'devdlink_rest_permission_data',
        'args'                => array(
            'page'         => array('type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 100000, 'sanitize_callback' => 'absint', 'validate_callback' => 'rest_validate_request_arg'),
            'per_page'     => array('type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 200, 'sanitize_callback' => 'absint', 'validate_callback' => 'rest_validate_request_arg'),
            'humans_only'  => array('type' => 'boolean', 'default' => false, 'validate_callback' => 'rest_validate_request_arg'),
            'show_ignored' => array('type' => 'boolean', 'default' => false, 'validate_callback' => 'rest_validate_request_arg'),
            'ignored_only' => array('type' => 'boolean', 'default' => false, 'validate_callback' => 'rest_validate_request_arg'),
            'search'       => array('type' => 'string', 'default' => '', 'maxLength' => 4096, 'sanitize_callback' => 'sanitize_text_field', 'validate_callback' => 'rest_validate_request_arg'),
            'orderby'      => array('type' => 'string', 'default' => 'human_hits', 'enum' => array('human_hits', 'bot_hits', 'last_seen'), 'sanitize_callback' => 'sanitize_key', 'validate_callback' => 'rest_validate_request_arg'),
            'order'        => array('type' => 'string', 'default' => 'DESC', 'enum' => array('ASC', 'DESC', 'asc', 'desc'), 'validate_callback' => 'rest_validate_request_arg'),
        ),
    ));

    register_rest_route($ns, '/404-suggest', array(
        'methods'             => 'POST',
        'callback'            => 'devdlink_rest_404_suggest',
        'permission_callback' => 'devdlink_rest_permission_data',
        'args'                => array(
            'id' => array('type' => 'integer', 'required' => true, 'minimum' => 1, 'sanitize_callback' => 'absint', 'validate_callback' => 'rest_validate_request_arg'),
        ),
    ));
    register_rest_route($ns, '/404-action', array(
        'methods'             => WP_REST_Server::CREATABLE,
        'callback'            => 'devdlink_rest_404_action',
        'permission_callback' => 'devdlink_rest_permission_mutate',
        'args'                => array(
            'id'     => devdlink_rest_arg_id(),
            'action' => array(
                'type'              => 'string',
                'required'          => true,
                'enum'              => array('ignore', 'unignore', 'delete'),
                'sanitize_callback' => 'sanitize_key',
                'validate_callback' => 'rest_validate_request_arg',
            ),
        ),
    ));
}

/**
 * Permission for one capability context. (The cookie-auth nonce is validated by core for
 * cookie-nonce requests; the object-level authorization for content mutation lives in
 * devdlink_authorize_link_sources() and no filter can bypass it.)
 *
 * @param string $context view|scan|data|configure|mutate
 * @return true|WP_Error
 */
function devdlink_rest_permission_for($context)
{
    if (!current_user_can(devdlink_capability($context))) {
        return new WP_Error('devdlink_forbidden', __('You do not have permission.', 'devdome-link-monitor'), array('status' => 403));
    }
    return true;
}
function devdlink_rest_permission_view() { return devdlink_rest_permission_for('view'); }
function devdlink_rest_permission_scan() { return devdlink_rest_permission_for('scan'); }
/** The loopback runner presents the internal tick key instead of a user session. */
function devdlink_rest_is_internal_tick()
{
    $hdr = isset($_SERVER['HTTP_X_DEVDLINK_TICK']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_DEVDLINK_TICK'])) : '';
    return $hdr !== '' && function_exists('devdlink_tick_key') && hash_equals(devdlink_tick_key(), $hdr);
}
function devdlink_rest_permission_tick()
{
    return devdlink_rest_is_internal_tick() ? true : devdlink_rest_permission_for('scan');
}
/**
 * One loopback tick: wait (at most a minute) for the backoff to expire and for whoever holds
 * the tick lock to let go, run one tick, and arm the next loopback. Nobody is waiting on this
 * request, so waiting here costs nothing; giving up after a minute still re-arms the chain.
 */
function devdlink_internal_tick()
{
    $deadline = time() + 60;
    while (time() < $deadline) {
        $job = devdlink_get_job();
        if (function_exists('devdlink_settings_read_failed') && devdlink_settings_read_failed()) {
            sleep(1); // the settings table could not be read: retry within the window, never "no job"
            continue;
        }
        // "finalizing" counts: on a quiet site this loopback may be the only thing that ever
        // resumes a finalize whose process died, so it must not bow out here.
        if (!$job || !in_array($job['status'], array('running', 'finalizing'), true)) {
            return;
        }
        $due = !empty($job['next_tick_at']) ? (int) $job['next_tick_at'] - time() : 0;
        if ($due > 0) {
            sleep(min($due, 20));
            continue;
        }
        if (function_exists('devdlink_tick_lock_held') && devdlink_tick_lock_held()) {
            sleep(1);
            continue;
        }
        devdlink_run_tick();
        break;
    }
    devdlink_spawn_tick();
}
function devdlink_rest_permission_data() { return devdlink_rest_permission_for('data'); }
function devdlink_rest_permission_mutate() { return devdlink_rest_permission_for('mutate'); }

/**
 * URLs, referrers, user agents, job state and exports must never sit in a shared or
 * intermediary cache: every response from this namespace is private and no-store.
 *
 * @param WP_HTTP_Response $response
 * @param WP_REST_Server   $server
 * @param WP_REST_Request  $request
 * @return WP_HTTP_Response
 */
function devdlink_rest_no_store($response, $server, $request)
{
    if (strpos((string) $request->get_route(), '/devdlink/v1') === 0 && is_object($response) && method_exists($response, 'header')) {
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private, max-age=0');
        $response->header('Pragma', 'no-cache');
        $response->header('Vary', 'Cookie');
    }
    return $response;
}
add_filter('rest_post_dispatch', 'devdlink_rest_no_store', 10, 3);

/**
 * HTTP status for a handler WP_Error: an object-level authorization refusal is 403, a stale
 * or partially applied mutation is 409 (the caller's view of the content no longer matches),
 * every other handler error stays a 400 bad request.
 *
 * @param WP_Error $error
 * @return int
 */
function devdlink_rest_error_status($error)
{
    $code = $error->get_error_code();
    if ($code === 'devdlink_forbidden') {
        return 403;
    }
    if (in_array($code, array('devdlink_partial', 'devdlink_not_in_content', 'devdlink_job_running', 'devdlink_duplicate'), true)) {
        return 409;
    }
    return 400;
}

/** POST /start-scan { mode: full|recheck } */
function devdlink_rest_start_scan(WP_REST_Request $request)
{
    $mode = sanitize_key((string) $request->get_param('mode'));
    $type = ($mode === 'recheck') ? 'recheck' : 'scan';
    $job = devdlink_start_job($type);
    if (is_wp_error($job)) {
        return new WP_Error($job->get_error_code(), $job->get_error_message(), array('status' => 409));
    }
    // Respond IMMEDIATELY — the poller (which also ticks) starts the work within a second.
    // Running the first tick here would block this response for the whole first chunk.
    return rest_ensure_response(devdlink_job_progress());
}

/**
 * GET /scan-progress — READ ONLY. It performs no write, advances no job and sends no
 * outbound request, so polling it (or replaying the URL) can never do work.
 */
function devdlink_rest_progress(WP_REST_Request $request)
{
    return rest_ensure_response(devdlink_job_progress());
}

/**
 * POST /scan-tick — the authorized work claim. Advances at most one bounded slice of the
 * running job and returns the progress snapshot. Requires the 'scan' capability because it
 * causes outbound requests.
 */
function devdlink_rest_scan_tick(WP_REST_Request $request)
{
    $job = devdlink_get_job();
    // An internal (loopback) tick with an unreadable settings table must not bow out as "no
    // job": the runner retries the read inside its 60-second window and re-arms the chain.
    $unreadable = function_exists('devdlink_settings_read_failed') && devdlink_settings_read_failed();
    if (($job && in_array($job['status'], array('running', 'finalizing'), true)) || ($unreadable && devdlink_rest_is_internal_tick())) {
        if (devdlink_rest_is_internal_tick()) {
            // The loopback runner: nobody is waiting on this response, so answer at once, keep
            // working after the caller hangs up, sit out a short backoff (the two-strike wait,
            // at most 20s), run one tick, and let that tick spawn the next one. That chain is
            // what finishes a scan with no browser open and no visitors.
            ignore_user_abort(true);
            $detached = false;
            if (function_exists('fastcgi_finish_request') && !headers_sent()) {
                status_header(202);
                header('Content-Type: application/json; charset=utf-8');
                echo wp_json_encode(array('accepted' => true));
                fastcgi_finish_request();
                $detached = true;
            }
            devdlink_internal_tick();
            if ($detached) {
                exit;
            }
            return rest_ensure_response(devdlink_job_progress());
        }
        devdlink_run_tick();
    }
    return rest_ensure_response(devdlink_job_progress());
}

/** POST /job-control { action: pause|resume|cancel } */
function devdlink_rest_job_control(WP_REST_Request $request)
{
    $action = sanitize_key((string) $request->get_param('action'));
    if ($action === 'pause' || $action === 'resume') {
        $changed = ($action === 'pause') ? devdlink_pause_job() : devdlink_resume_job();
        if ($changed === false) {
            return new WP_Error('devdlink_db_write', __('The change could not be saved to the database.', 'devdome-link-monitor'), array('status' => 500));
        }
    } elseif ($action === 'cancel') {
        $cancelled = devdlink_cancel_job();
        if (is_wp_error($cancelled)) {
            return $cancelled; // 409 "already finishing" or 500 "could not be saved"
        }
    }
    return rest_ensure_response(devdlink_job_progress());
}

/* ------------------------------ links list ------------------------------ */

/**
 * Owns the filtered/paginated links SQL.
 *
 * @param array $args { scan_id, filter, page, per_page, search }
 * @return array { total: int, rows: array }
 */
function devdlink_links_query($args)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_links';

    $scan_id = isset($args['scan_id']) ? (int) $args['scan_id'] : 0;
    $filter = isset($args['filter']) ? (string) $args['filter'] : 'all';
    $page = isset($args['page']) ? max(1, (int) $args['page']) : 1;
    $per = isset($args['per_page']) ? max(1, min(200, (int) $args['per_page'])) : 20;
    $search = isset($args['search']) ? (string) $args['search'] : '';
    $offset = ($page - 1) * $per;

    $where = array('scan_id = %d');
    $params = array($scan_id);

    switch ($filter) {
        case 'broken':
            $where[] = 'status = %s';
            $params[] = 'broken';
            break;
        case 'redirects':
            $where[] = 'status = %s';
            $params[] = 'redirect';
            break;
        case 'timeouts':
            $where[] = 'status = %s';
            $params[] = 'timeout';
            break;
        case 'blocked':
            $where[] = 'status = %s';
            $params[] = 'blocked';
            break;
        case 'internal':
            $where[] = 'is_internal = 1';
            $where[] = "status <> 'pending'";
            break;
        case 'external':
            $where[] = 'is_internal = 0';
            $where[] = "status <> 'pending'";
            break;
        default: // all = every checked (non-pending) link
            $where[] = "status <> 'pending'";
            break;
    }

    if ($search !== '') {
        $where[] = 'url LIKE %s';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    $where_sql = implode(' AND ', $where);

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal links count for the review list; WHERE values bound via prepare, table name is the internal prefixed constant.
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params));

    $params[] = $per;
    $params[] = $offset;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders inside $where_sql are counted by the caller; internal links page read for the review list; all values bound via prepare, table name is the internal prefixed constant.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id ASC LIMIT %d OFFSET %d",
        $params
    ), ARRAY_A);

    return array('total' => $total, 'rows' => (array) $rows);
}

/**
 * Batch-load the source posts for a set of link ids.
 *
 * @param int[] $link_ids
 * @return array link_id => array of { post_id, title, edit_url }
 */
function devdlink_rest_link_sources($link_ids)
{
    global $wpdb;
    $link_ids = array_values(array_unique(array_filter(array_map('intval', (array) $link_ids))));
    if (!$link_ids) {
        return array();
    }
    $table = $wpdb->prefix . 'devdlink_link_sources';
    $ph = implode(',', array_fill(0, count($link_ids), '%d'));
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal link_sources read; ids bound via prepared IN placeholders, table name is the internal prefixed constant.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT link_id, post_id, usage_type, anchor_text, occurrences FROM {$table} WHERE link_id IN ({$ph}) ORDER BY post_id ASC, usage_type ASC", $link_ids), ARRAY_A);

    $map = array();
    foreach ((array) $rows as $r) {
        $lid = (int) $r['link_id'];
        $pid = (int) $r['post_id'];
        if (!isset($map[$lid])) {
            $map[$lid] = array();
        }
        $title = get_the_title($pid);
        $pto   = get_post_type_object((string) get_post_type($pid));
        $map[$lid][] = array(
            'post_id'  => $pid,
            'title'    => $title !== '' ? $title : ('#' . $pid),
            'edit_url' => (string) get_edit_post_link($pid, 'raw'),
            'view_url' => (string) get_permalink($pid),
            'type'     => ($pto && isset($pto->labels->singular_name)) ? (string) $pto->labels->singular_name : ucfirst((string) get_post_type($pid)),
            'usage'    => (isset($r['usage_type']) && $r['usage_type'] === 'img') ? 'img' : 'href',
            'anchor'   => isset($r['anchor_text']) ? (string) $r['anchor_text'] : '',
            'occurrences' => isset($r['occurrences']) ? max(1, (int) $r['occurrences']) : 1,
        );
    }
    return $map;
}

/**
 * Shape one links DB row for the REST response.
 *
 * @param array      $row     associative links row (DB column keys)
 * @param array|null $sources pre-resolved sources list, or null to load them now
 * @return array
 */
/** 'href' when any occurrence is an anchor (that is what makes it editable/unlinkable), else the row's type. */
function devdlink_rest_link_usage($row, $sources)
{
    foreach ((array) $sources as $sp) {
        if (isset($sp['usage']) && $sp['usage'] === 'href') {
            return 'href';
        }
    }
    return isset($row['link_type']) ? (string) $row['link_type'] : 'href';
}

function devdlink_rest_format_link($row, $sources = null)
{
    $row = (array) $row;
    $id = (int) $row['id'];
    if ($sources === null) {
        $map = devdlink_rest_link_sources(array($id));
        $sources = isset($map[$id]) ? $map[$id] : array();
    }
    $status = isset($row['status']) ? (string) $row['status'] : '';
    $class = isset($row['error_class']) ? (string) $row['error_class'] : 'none';
    return array(
        'id'            => $id,
        'url'           => isset($row['url']) ? (string) $row['url'] : '',
        'host'          => isset($row['host']) ? (string) $row['host'] : '',
        'internal'      => !empty($row['is_internal']),
        'type'          => devdlink_rest_link_usage($row, $sources),
        'anchor'        => isset($row['anchor_text']) ? (string) $row['anchor_text'] : '',
        'status'        => $status,
        'error_class'   => $class,
        'status_label'  => function_exists('devdlink_status_label') ? devdlink_status_label($status, $class) : $status,
        'status_short'  => function_exists('devdlink_status_label') ? devdlink_status_label($status, '') : $status,
        'status_detail' => function_exists('devdlink_error_class_label') ? devdlink_error_class_label((string) $class) : '',
        'error_message' => isset($row['error_message']) ? (string) $row['error_message'] : '',
        'http_code'     => isset($row['http_code']) ? (int) $row['http_code'] : 0,
        'redirect_url'  => isset($row['redirect_url']) ? (string) $row['redirect_url'] : '',
        'redirect_hops' => isset($row['redirect_hops']) ? (int) $row['redirect_hops'] : 0,
        'checked_at'    => isset($row['checked_at']) ? (string) $row['checked_at'] : '',
        'sources'       => $sources,
    );
}

/** Read one links row by id (prepared). */
function devdlink_rest_get_link_row($link_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_links';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal single links row read; id bound via prepare, table name is the internal prefixed constant.
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $link_id), ARRAY_A);
}

/** GET /links { scan_id, filter, page, per_page, search } */
function devdlink_rest_links(WP_REST_Request $request)
{
    $scan_id = (int) $request->get_param('scan_id');
    if (!$scan_id) {
        $scan_id = devdlink_get_int('last_scan_id', 0);
    }
    $page = max(1, (int) $request->get_param('page'));
    $per = max(1, min(200, (int) ($request->get_param('per_page') ?: 20)));

    $valid_filter = array('all', 'broken', 'redirects', 'timeouts', 'blocked', 'internal', 'external');
    $filter = sanitize_key((string) $request->get_param('filter'));
    $filter = in_array($filter, $valid_filter, true) ? $filter : 'all';

    $search = sanitize_text_field((string) $request->get_param('search'));

    $res = devdlink_links_query(array(
        'scan_id'  => $scan_id,
        'filter'   => $filter,
        'page'     => $page,
        'per_page' => $per,
        'search'   => $search,
    ));

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

    return rest_ensure_response(array(
        'scan_id'  => $scan_id,
        'page'     => $page,
        'per_page' => $per,
        'total'    => (int) $res['total'],
        'pages'    => (int) ceil($res['total'] / $per),
        'counts'   => devdlink_links_counts($scan_id),
        'items'    => $items,
    ));
}

/** Per-filter totals for the pills (same status buckets as devdlink_links_query). */
function devdlink_links_counts($scan_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_links';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table; scan id bound via prepare.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT status, is_internal, COUNT(*) AS n FROM {$table} WHERE scan_id = %d AND status <> 'pending' GROUP BY status, is_internal",
        (int) $scan_id
    ), ARRAY_A);
    $c = array('all' => 0, 'broken' => 0, 'redirects' => 0, 'timeouts' => 0, 'blocked' => 0, 'internal' => 0, 'external' => 0);
    $map = array('broken' => 'broken', 'redirect' => 'redirects', 'timeout' => 'timeouts', 'blocked' => 'blocked');
    foreach ((array) $rows as $r) {
        $n = (int) $r['n'];
        $c['all'] += $n;
        if (isset($map[$r['status']])) {
            $c[$map[$r['status']]] += $n;
        }
        $c[(int) $r['is_internal'] ? 'internal' : 'external'] += $n;
    }
    return $c;
}

/* ------------------------------ link actions ---------------------------- */

/** POST /recheck-link { link_id } — synchronous single check. */
function devdlink_rest_recheck_link(WP_REST_Request $request)
{
    $link_id = (int) $request->get_param('link_id');
    if (!$link_id) {
        return new WP_Error('devdlink_no_link', __('No link specified.', 'devdome-link-monitor'), array('status' => 400));
    }
    $row = devdlink_recheck_single($link_id);
    if (is_wp_error($row)) {
        return new WP_Error($row->get_error_code(), $row->get_error_message(), array('status' => 400));
    }
    // Checker returns the updated DB row; shape it like the list items.
    $row = (array) $row;
    $out = isset($row['is_internal']) ? devdlink_rest_format_link($row) : $row;
    return rest_ensure_response($out);
}

/** POST /edit-url { link_id, new_url } — safe str_replace across every source post. */
function devdlink_rest_edit_url(WP_REST_Request $request)
{
    $link_id = (int) $request->get_param('link_id');
    $new_url = esc_url_raw(trim((string) $request->get_param('new_url')));
    if (!$link_id) {
        return new WP_Error('devdlink_no_link', __('No link specified.', 'devdome-link-monitor'), array('status' => 400));
    }
    if ($new_url === '' || !preg_match('#^https?://#i', $new_url)) {
        return new WP_Error('devdlink_bad_url', __('Enter a full http(s) URL.', 'devdome-link-monitor'), array('status' => 400));
    }
    $res = devdlink_edit_link_url($link_id, $new_url);
    if (is_wp_error($res)) {
        return new WP_Error($res->get_error_code(), $res->get_error_message(), array('status' => devdlink_rest_error_status($res)));
    }
    $row = devdlink_rest_get_link_row($link_id);
    return rest_ensure_response(array(
        'updated_posts' => isset($res['updated_posts']) ? (int) $res['updated_posts'] : 0,
        'link'          => $row ? devdlink_rest_format_link($row) : null,
    ));
}

/** POST /unlink { link_id } — href links only (400 for img). */
function devdlink_rest_unlink(WP_REST_Request $request)
{
    $link_id = (int) $request->get_param('link_id');
    if (!$link_id) {
        return new WP_Error('devdlink_no_link', __('No link specified.', 'devdome-link-monitor'), array('status' => 400));
    }
    $res = devdlink_unlink($link_id);
    if (is_wp_error($res)) {
        return new WP_Error($res->get_error_code(), $res->get_error_message(), array('status' => devdlink_rest_error_status($res)));
    }
    return rest_ensure_response(array(
        'updated_posts' => isset($res['updated_posts']) ? (int) $res['updated_posts'] : 0,
    ));
}

/** POST /dismiss { link_id } */
function devdlink_rest_dismiss(WP_REST_Request $request)
{
    global $wpdb;
    $link_id = (int) $request->get_param('link_id');
    if (!$link_id || !devdlink_rest_get_link_row($link_id)) {
        return new WP_Error('devdlink_no_link', __('That link no longer exists.', 'devdome-link-monitor'), array('status' => 404));
    }
    $table = $wpdb->prefix . 'devdlink_links';
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links row status flip; values bound via $wpdb->update, table name is the internal prefixed constant.
    $written = $wpdb->update($table, array('status' => 'dismissed'), array('id' => $link_id), array('%s'), array('%d'));
    if ($written === false) {
        return new WP_Error('devdlink_db_write', __('The change could not be saved to the database.', 'devdome-link-monitor'), array('status' => 500));
    }
    // broken -> dismissed changes the counts and the health score on the Dashboard right away.
    if (function_exists('devdlink_refresh_summary')) {
        devdlink_refresh_summary();
    }
    return rest_ensure_response(array('status' => 'dismissed'));
}

/* ------------------------------ 404 log --------------------------------- */

/** Make sure LANE B's 404 query helpers are loaded (they live in an is_admin()-only file). */
function devdlink_rest_load_404_helpers()
{
    if (!function_exists('devdlink_404_rows') && defined('DEVDLINK_DIR')) {
        $file = DEVDLINK_DIR . 'includes/fourohfour-admin.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
    return function_exists('devdlink_404_rows');
}

/** GET /404s { page, per_page, humans_only, show_ignored, orderby, order } */
function devdlink_rest_404s(WP_REST_Request $request)
{
    if (!devdlink_rest_load_404_helpers()) {
        return new WP_Error('devdlink_unavailable', __('The 404 log is unavailable.', 'devdome-link-monitor'), array('status' => 500));
    }

    $page = max(1, (int) $request->get_param('page'));
    $per = max(1, min(200, (int) ($request->get_param('per_page') ?: 20)));
    $humans_only = (int) (bool) $request->get_param('humans_only');
    $show_ignored = (int) (bool) $request->get_param('show_ignored');

    $valid_orderby = array('human_hits', 'bot_hits', 'last_seen');
    $orderby = sanitize_key((string) $request->get_param('orderby'));
    $orderby = in_array($orderby, $valid_orderby, true) ? $orderby : 'human_hits';
    $order = strtoupper((string) $request->get_param('order')) === 'ASC' ? 'ASC' : 'DESC';

    $res = devdlink_404_rows(array(
        'page'         => $page,
        'per_page'     => $per,
        'humans_only'  => $humans_only,
        'show_ignored' => $show_ignored,
        'ignored_only' => (int) (bool) $request->get_param('ignored_only'),
        'orderby'      => $orderby,
        'order'        => $order,
        'search'       => sanitize_text_field((string) $request->get_param('search')),
    ));

    $items = array();
    foreach ((array) $res['rows'] as $r) {
        $r = (array) $r;
        $path = isset($r['path']) ? (string) $r['path'] : '';
        $items[] = array(
            'id'         => isset($r['id']) ? (int) $r['id'] : 0,
            'path'       => $path,
            'human_hits' => isset($r['human_hits']) ? (int) $r['human_hits'] : 0,
            'bot_hits'   => isset($r['bot_hits']) ? (int) $r['bot_hits'] : 0,
            // Redacted again on the way out: rows written by an older build predate the
            // origin+path referrer policy and may still carry a query string.
            'referrer'   => isset($r['last_referrer']) ? devdlink_redact_secrets((string) $r['last_referrer']) : '',
            'ua'         => isset($r['last_ua']) ? devdlink_redact_secrets((string) $r['last_ua']) : '',
            'ignored'    => !empty($r['ignored']) ? 1 : 0,
            'first_seen' => isset($r['first_seen']) ? (string) $r['first_seen'] : '',
            'last_seen'  => isset($r['last_seen']) ? (string) $r['last_seen'] : '',
            'suggestion' => null,
        );
    }

    $total = isset($res['total']) ? (int) $res['total'] : 0;

    return rest_ensure_response(array(
        'page'       => $page,
        'per_page'   => $per,
        'total'      => $total,
        'counts'     => devdlink_404_pill_counts(),
        'pages'      => (int) ceil($total / $per),
        'items'      => $items,
        // Honest provenance for the bot/human split shown next to every row.
        'classifier' => function_exists('devdlink_classifier_name') ? devdlink_classifier_name() : '',
    ));
}

/**
 * POST /404-suggest { id }: the fuzzy redirect suggestion for ONE 404 path, computed on
 * request (never for every row of a list page; the old per-row computation was the N+1).
 */
function devdlink_rest_404_suggest(WP_REST_Request $request)
{
    global $wpdb;
    if (!devdlink_rest_load_404_helpers() || !function_exists('devdlink_suggest_redirect')) {
        return new WP_Error('devdlink_unavailable', __('The 404 log is unavailable.', 'devdome-link-monitor'), array('status' => 500));
    }
    $id = (int) $request->get_param('id');
    $table = $wpdb->prefix . 'devdlink_404s';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal 404 row read; id bound via prepare.
    $path = $wpdb->get_var($wpdb->prepare("SELECT path FROM {$table} WHERE id = %d", $id));
    if ($path === null) {
        return new WP_Error('devdlink_not_found', __('404 entry not found.', 'devdome-link-monitor'), array('status' => 404));
    }
    $sug = devdlink_suggest_redirect((string) $path);
    return rest_ensure_response(array(
        'id'         => $id,
        'suggestion' => is_array($sug) ? array(
            'url'      => (string) $sug['url'],
            'label'    => (string) $sug['label'],
            'rm_url'   => (string) $sug['rm_url'],
            'htaccess' => (string) $sug['htaccess'],
        ) : null,
    ));
}

/** POST /404-action { id, action: ignore|unignore|delete } */
function devdlink_rest_404_action(WP_REST_Request $request)
{
    global $wpdb;
    $id = (int) $request->get_param('id');
    $action = sanitize_key((string) $request->get_param('action'));
    if (!$id || !in_array($action, array('ignore', 'unignore', 'delete'), true)) {
        return new WP_Error('devdlink_bad_action', __('Invalid 404 action.', 'devdome-link-monitor'), array('status' => 400));
    }

    $table = $wpdb->prefix . 'devdlink_404s';
    if ($action === 'delete') {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal 404 row delete; values bound via $wpdb->delete, table name is the internal prefixed constant.
        $written = $wpdb->delete($table, array('id' => $id), array('%d'));
        // Destructive: record who removed which row - after the database confirmed it.
        if ($written !== false && function_exists('devdlink_record_mutation')) {
            devdlink_record_mutation('delete_404', $id, array(), '404 row #' . $id, '', 'ok');
        }
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal 404 row ignore flag flip; values bound via $wpdb->update, table name is the internal prefixed constant.
        $written = $wpdb->update($table, array('ignored' => ($action === 'ignore') ? 1 : 0), array('id' => $id), array('%d'), array('%d'));
    }

    if ($written === false) {
        return new WP_Error('devdlink_db_write', __('The change could not be saved to the database.', 'devdome-link-monitor'), array('status' => 500));
    }
    if (function_exists('devdlink_refresh_summary')) {
        devdlink_refresh_summary();
    }

    return rest_ensure_response(array('ok' => true));
}

/** Pill totals for the 404 list: live (not ignored), live with human hits, ignored. */
function devdlink_404_pill_counts()
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_404s';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin's own table, no user input.
    $row = $wpdb->get_row("SELECT SUM(ignored = 0) AS all_n, SUM(ignored = 0 AND human_hits > 0) AS humans, SUM(ignored = 1) AS ignored FROM {$table}", ARRAY_A);
    return array(
        'all'     => $row ? (int) $row['all_n'] : 0,
        'humans'  => $row ? (int) $row['humans'] : 0,
        'ignored' => $row ? (int) $row['ignored'] : 0,
    );
}
