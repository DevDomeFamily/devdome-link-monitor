<?php
/**
 * Background processing / chunked job runner (engine cloned from Media Cleaner's queue.php).
 *
 * Persists ONE job's state in the settings store (key 'job') so it survives requests, timeouts
 * and power outages, and is fully resumable + pause/resume/cancel-able. A self-rescheduling
 * single WP-Cron event ('devdlink_run_job') drives ticks; the admin can also kick a tick via
 * REST (the poller). Job types: scan (full: extract -> check -> recheck -> finalize) and
 * recheck (re-verify the last completed scan's broken/timeout/blocked rows).
 */

defined('ABSPATH') || exit;

/** Current job state (array) or null when idle. */
function devdlink_get_job()
{
    $job = devdlink_get_setting('job', '');
    return is_array($job) ? $job : null;
}

/** Persist the job state. @return bool false when the database refused the write. */
function devdlink_save_job($job)
{
    return (bool) devdlink_update_setting('job', is_array($job) ? $job : array());
}

/** Clear the job. */
/** @return bool false when the database refused to clear the stored job. */
function devdlink_clear_job()
{
    return (bool) devdlink_update_setting('job', '');
}

/**
 * Persist a structured, user-visible error so it survives the page reload (the progress poller
 * only flashes it). Surfaced on the Dashboard with retry / dismiss actions.
 *
 * @param string $code    machine code (e.g. 'scan_failed', 'db', 'rest')
 * @param string $message human message
 * @param array  $context optional extra detail
 */
function devdlink_record_error($code, $message, $context = array())
{
    $log = devdlink_get_array('error_log');
    $log[] = array(
        'at'      => time(),
        'code'    => (string) $code,
        'message' => (string) $message,
        'context' => is_array($context) ? $context : array(),
    );
    if (count($log) > 50) {
        $log = array_slice($log, -50);
    }
    devdlink_update_setting('error_log', $log);
    devdlink_update_setting('last_error', array(
        'at'      => time(),
        'code'    => (string) $code,
        'message' => (string) $message,
        'context' => is_array($context) ? $context : array(),
    ));
}

/** The most recent persisted error (or null). */
function devdlink_last_error()
{
    $e = devdlink_get_setting('last_error', '');
    return is_array($e) && !empty($e['message']) ? $e : null;
}

/** Clear the surfaced error (user dismissed / retried). */
function devdlink_clear_last_error()
{
    devdlink_update_setting('last_error', '');
}

/**
 * Acquire the single START lease. The whole check/create/save sequence below is one critical
 * section: without it N simultaneous callers each read "no job", each INSERT a scans row and
 * each save the job setting — the audit measured 15 running scan rows and 14 orphans from 15
 * synchronized starts. add_option() is atomic (option_name is a UNIQUE key, so exactly one
 * INSERT wins), with a stale takeover so a crashed starter cannot wedge new work.
 *
 * @return bool true if THIS caller now holds the lease.
 */
function devdlink_acquire_start_lock()
{
    global $wpdb;
    $now  = time();
    $ttl  = 60; // a start is a few queries; 60s is a generous crash window.
    $mine = $now . '|' . uniqid('s', true); // "<timestamp>|<owner token>", like the tick and upgrade locks
    if (add_option('devdlink_start_lock', $mine, '', 'no')) {
        return $mine;
    }
    wp_cache_delete('devdlink_start_lock', 'options');
    $held_raw = (string) get_option('devdlink_start_lock', '');
    $held     = (int) $held_raw;
    if ($held && ($now - $held) < $ttl) {
        return false; // another start is in flight.
    }
    // Stale: only the UPDATE that still matches the stale value wins the takeover race.
    $rows = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        $mine,
        'devdlink_start_lock',
        $held_raw
    ));
    if ($rows === 1) {
        wp_cache_delete('devdlink_start_lock', 'options');
        return $mine;
    }
    return false;
}

/**
 * Release the start lease - only when it is still OURS: a start that ran past the TTL and was
 * taken over must not delete the successor's lease.
 *
 * @param string|null $mine the value devdlink_acquire_start_lock() returned (null = unconditional, tests only)
 */
function devdlink_release_start_lock($mine = null)
{
    global $wpdb;
    if ($mine === null) {
        delete_option('devdlink_start_lock');
        return;
    }
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership-checked lease release; values bound via prepare.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'devdlink_start_lock', (string) $mine));
    wp_cache_delete('devdlink_start_lock', 'options');
    if ((string) get_option('devdlink_start_lock', '') === (string) $mine) {
        delete_option('devdlink_start_lock'); // still ours (cached row): release through the API too.
    }
}

/**
 * Close scans rows still marked 'running' while no job owns them. Crashed ticks, killed PHP
 * workers and (before the start lease existed) duplicate starts all leave rows that would
 * otherwise stay 'running' for ever. Called under the start lease only.
 *
 * @return int rows reconciled
 */
function devdlink_reconcile_orphan_scans()
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_scans';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table reconciliation; values bound via prepare.
    return (int) $wpdb->query($wpdb->prepare(
        "UPDATE {$table} SET status = 'abandoned', finished_at = %s WHERE status = 'running'",
        current_time('mysql')
    ));
}

/**
 * Start a new job. Returns the job array, or WP_Error if one is already running.
 *
 * @param string $type scan | recheck
 * @param array  $args type-specific payload
 * @return array|WP_Error
 */
function devdlink_start_job($type, $args = array())
{
    $start_lock = devdlink_acquire_start_lock();
    if (!$start_lock) {
        return new WP_Error('devdlink_job_running', __('Another job is already in progress.', 'devdome-link-monitor'));
    }
    try {
        return devdlink_start_job_locked($type, $args);
    } finally {
        devdlink_release_start_lock($start_lock);
    }
}

/**
 * The start sequence itself. NEVER call directly — devdlink_start_job() holds the lease
 * that makes check/create/save atomic.
 *
 * @param string $type
 * @param array  $args
 * @return array|WP_Error
 */
function devdlink_start_job_locked($type, $args = array())
{
    global $wpdb;

    // Drop the request-level settings cache: a job written by the caller that just released
    // the lease must be visible here, or the check below decides on a stale snapshot.
    unset($GLOBALS['devdlink_cache']);

    $existing = devdlink_get_job();
    if (devdlink_settings_read_failed()) {
        return new WP_Error('devdlink_db_read', __('The scan could not be started: the job state could not be read from the database.', 'devdome-link-monitor'));
    }
    if ($existing && in_array($existing['status'], array('running', 'paused', 'finalizing'), true)) {
        // Self-heal: a job whose last heartbeat is older than 10 minutes is dead (crashed tick,
        // closed tab, power loss). Never let it block new work forever.
        $age = time() - (int) $existing['updated_at'];
        if (in_array($existing['status'], array('running', 'finalizing'), true) && $age > 600) {
            // Close the dead job's scans row so it never lingers as 'running' forever
            // (mirrors devdlink_cancel_job).
            $stale_row = 0;
            if ($existing['type'] === 'recheck') {
                $stale_row = isset($existing['args']['recheck_row']) ? (int) $existing['args']['recheck_row'] : 0;
            } elseif (!empty($existing['scan_id'])) {
                $stale_row = (int) $existing['scan_id'];
            }
            if ($stale_row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table status update on stale-job self-heal.
                $wpdb->update(
                    $wpdb->prefix . 'devdlink_scans',
                    array('status' => 'cancelled', 'finished_at' => current_time('mysql')),
                    // Only a row that is still running: a dead finalizing job may already have
                    // recorded its scan as completed, and that result stays.
                    array('id' => $stale_row, 'status' => 'running'),
                    array('%s', '%s'),
                    array('%d', '%s')
                );
            }
                        // A dead finalizing job may have recorded its scan as completed without settling
            // the latest-scan pointer: repair it from the newest completed full scan.
            if ($existing['status'] === 'finalizing') {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans audit table.
                $newest_done = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}devdlink_scans WHERE status = 'completed' AND scan_type = 'full'");
                if ($newest_done > devdlink_get_int('last_scan_id', 0)) {
                    devdlink_update_setting('last_scan_id', $newest_done);
                    devdlink_update_setting('last_scan_at', time());
                }
            }
            // The dead job's tick lock (if any) is NOT deleted here: the lock now carries an
            // owner token and only its owner releases it; a crashed holder is reaped by the
            // stale-lock takeover in devdlink_acquire_tick_lock().
            devdlink_clear_job();
        } else {
            return new WP_Error('devdlink_job_running', __('Another job is already in progress.', 'devdome-link-monitor'));
        }
    }

    $type = in_array($type, array('scan', 'recheck'), true) ? $type : 'scan';

    $job = array(
        'type'          => $type,
        'status'        => 'running',
        // Unique identity for THIS job. Every later clear/save/cancel decision compares
        // job_id, never timestamps - two jobs created in the same second stay distinct.
        'job_id'        => uniqid('lm', true),
        'created_at'    => time(),
        'updated_at'    => time(),
        'phase'         => 'extract',
        'cursor'        => 0,
        'scan_id'       => 0,
        'extract_total' => 0,
        'extract_done'  => 0,
        'check_total'   => 0,
        'check_done'    => 0,
        'recheck_total' => 0,
        'recheck_done'  => 0,
        'errors'        => 0,
        'message'       => '',
        'tick_delay'    => 1,
        'next_tick_at'  => 0,
        'args'          => is_array($args) ? $args : array(),
    );

    $scans_table = $wpdb->prefix . 'devdlink_scans';

    // No job owns anything at this point (the lease is held and the check above cleared or
    // rejected any existing job), so every remaining 'running' scans row is an orphan.
    devdlink_reconcile_orphan_scans();

    if ($type === 'scan') {
        $scan_id = devdlink_create_scan('full', get_current_user_id());
        if (!$scan_id) {
            return new WP_Error('devdlink_scan_failed', __('Could not create a scan session.', 'devdome-link-monitor'));
        }
        $job['scan_id'] = $scan_id;
        $job['extract_total'] = devdlink_total_posts();
        $job['message'] = __('Extracting links from content...', 'devdome-link-monitor');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table update (progress metadata).
        $wpdb->update(
            $scans_table,
            array('total_posts' => (int) $job['extract_total']),
            array('id' => (int) $scan_id),
            array('%d'),
            array('%d')
        );
    } else {
        // Re-check: reuse the LAST COMPLETED full scan's link set and start straight at the
        // recheck phase. Flagged rows are re-checked IN PLACE (selected by status + a
        // checked_at cutoff) — never destructively reset — so a cancelled or crashed recheck
        // leaves the completed scan's data fully intact.
        $links_scan = devdlink_get_int('last_scan_id', 0);
        $valid = 0;
        if ($links_scan) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table read; id bound via prepare.
            $valid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$scans_table} WHERE id = %d AND status = 'completed'",
                (int) $links_scan
            ));
        }
        if (!$valid) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; constant statement, nothing to prepare.
            $links_scan = (int) $wpdb->get_var("SELECT MAX(id) FROM {$scans_table} WHERE status = 'completed' AND scan_type = 'full'");
        }
        if (!$links_scan) {
            return new WP_Error('devdlink_no_scan', __('Run a full scan first - there are no checked links to re-check yet.', 'devdome-link-monitor'));
        }

        $recheck_row = devdlink_create_scan('recheck', get_current_user_id());
        if (!$recheck_row) {
            return new WP_Error('devdlink_scan_failed', __('Could not create a scan session.', 'devdome-link-monitor'));
        }

        $links_table = $wpdb->prefix . 'devdlink_links';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table count; scan id bound via prepare.
        $flagged = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$links_table} WHERE scan_id = %d AND status IN ('broken', 'timeout', 'blocked')",
            (int) $links_scan
        ));

        $job['scan_id'] = (int) $links_scan;
        $job['args']['recheck_row'] = (int) $recheck_row;
        // Rows with checked_at older than this cutoff are the ones to re-verify; each check
        // stamps a fresh checked_at, which is what moves the job forward.
        $job['args']['recheck_cutoff'] = current_time('mysql');
        $job['phase'] = 'recheck';
        $job['recheck_total'] = max(0, $flagged);
        $job['message'] = __('Re-checking flagged links...', 'devdome-link-monitor');
    }

    if (!devdlink_save_job($job)) {
        // The audit row exists but no job owns it: close THAT row (a recheck's own row, never
        // the completed full scan it reuses) instead of leaving an orphan "running" row and
        // reporting a start that never happened.
        devdlink_mark_job_scan_cancelled($job);
        devdlink_record_error('start_save', 'The job state could not be saved to the database; the start was rolled back.', array('type' => $type));
        return new WP_Error('devdlink_db_write', __('The scan could not be started: the job state could not be saved to the database.', 'devdome-link-monitor'));
    }
    devdlink_schedule_tick(1);
    return $job;
}

/** Schedule the next tick (single cron event). */
function devdlink_schedule_tick($delay = 1)
{
    if (!wp_next_scheduled('devdlink_run_job')) {
        wp_schedule_single_event(time() + max(1, (int) $delay), 'devdlink_run_job');
    }
    // WP-Cron only fires on page loads: on a quiet site a scan whose admin tab was closed sat
    // for over an hour (test1, 2026-08-25). A fire-and-forget loopback request to our own tick
    // route keeps it moving; the single-holder tick lock makes any overlap harmless.
    devdlink_spawn_tick();
}

/** Internal key that lets the loopback request call the tick route without a user session. */
function devdlink_tick_key()
{
    $key = get_option('devdlink_tick_key', '');
    if (!is_string($key) || strlen($key) < 32) {
        $key = wp_generate_password(48, false);
        update_option('devdlink_tick_key', $key, false);
    }
    return $key;
}

/**
 * Arm one loopback POST to our own scan-tick route, sent at shutdown. At shutdown the tick lock
 * this request may hold is already released; a spawn from inside the tick made the child lose
 * the lock and the chain died (measured on test1, 2026-08-25).
 */
function devdlink_spawn_tick()
{
    static $armed = false;
    if ($armed || !function_exists('rest_url')) {
        return;
    }
    $armed = true;
    register_shutdown_function('devdlink_spawn_tick_now');
}

/** Is a fresh (non-stale) tick lock held right now? Bypasses the per-request option cache. */
function devdlink_tick_lock_held()
{
    wp_cache_delete(devdlink_lock_key(), 'options');
    $held = (int) get_option(devdlink_lock_key(), '');
    return $held > 0 && (time() - $held) < 600;
}

/** The loopback POST itself: once per request, and only while a job is still running. */
function devdlink_spawn_tick_now()
{
    static $sent = false;
    if ($sent) {
        return;
    }
    $sent = true;
    $job = devdlink_get_job();
    // Unreadable settings: the job state is unknown, so keep the chain alive rather than
    // assuming there is nothing to do.
    if (!devdlink_settings_read_failed() && (!$job || !in_array($job['status'], array('running', 'finalizing'), true))) {
        return;
    }
    // 1s, not 0.01: behind a TLS proxy a 10ms timeout aborts during the handshake and the
    // request never reaches PHP (measured on test1). The tick route replies at once, so the
    // spawning request is held for a few ms, never the whole second.
    wp_remote_post(rest_url('devdlink/v1/scan-tick'), array(
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => false, // our own site; a self-signed or proxy certificate must not stop the runner.
        'headers'   => array('X-DevdLink-Tick' => devdlink_tick_key()),
        'body'      => '',
    ));
}

/** Pause / resume / cancel controls. */
/** @return bool false when the new state could not be saved (the UI must not claim it). */
function devdlink_pause_job()
{
    $job = devdlink_get_job();
    if (devdlink_settings_read_failed()) {
        return false;
    }
    if ($job && $job['status'] === 'running') {
        $job['status'] = 'paused';
        $job['updated_at'] = time();
        return devdlink_save_job($job);
    }
    return true;
}
/** @return bool false when the new state could not be saved (the UI must not claim it). */
function devdlink_resume_job()
{
    $job = devdlink_get_job();
    if (devdlink_settings_read_failed()) {
        return false;
    }
    if ($job && $job['status'] === 'paused') {
        $job['status'] = 'running';
        $job['updated_at'] = time();
        if (!devdlink_save_job($job)) {
            return false;
        }
        devdlink_schedule_tick(1);
    }
    return true;
}
/** Mark the scans row of a job cancelled (a recheck marks its own audit row only). */
function devdlink_mark_job_scan_cancelled($job)
{
    global $wpdb;
    $row_id = 0;
    if (isset($job['type']) && $job['type'] === 'recheck') {
        $row_id = isset($job['args']['recheck_row']) ? (int) $job['args']['recheck_row'] : 0;
    } elseif (!empty($job['scan_id'])) {
        $row_id = (int) $job['scan_id'];
    }
    if ($row_id) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans audit row.
        $written = $wpdb->update(
            $wpdb->prefix . 'devdlink_scans',
            array('status' => 'cancelled', 'finished_at' => current_time('mysql')),
            array('id' => $row_id, 'status' => 'running'), // a completed row is never rewritten
            array('%s', '%s'),
            array('%d', '%s')
        );
        if ($written === false) {
            return false;
        }
        // The cached summary may have been computed while this row still read "completed"
        // (a cancel that landed during finalize): recount now that it is cancelled.
        if (function_exists('devdlink_refresh_summary')) {
            devdlink_refresh_summary();
        }
    }
    return true;
}

function devdlink_cancel_job()
{
    global $wpdb;
    $job = devdlink_get_job();
    if (devdlink_settings_read_failed()) {
        return new WP_Error('devdlink_db_read', __('The cancel could not be processed: the job state could not be read from the database.', 'devdome-link-monitor'), array('status' => 500));
    }
    if ($job && isset($job['status']) && $job['status'] === 'finalizing') {
        return new WP_Error('devdlink_finishing', __('The scan is already finishing and cannot be cancelled now.', 'devdome-link-monitor'), array('status' => 409));
    }
    if ($job && isset($job['status']) && in_array($job['status'], array('completed', 'cancelled'), true)) {
        // Nothing is running: the job is a finished record. Clear it, but never rewrite the
        // scan row - a completed scan must stay completed (it is the new-broken baseline).
        devdlink_clear_job();
        wp_clear_scheduled_hook('devdlink_run_job');
        return true;
    }
    $db_error = new WP_Error('devdlink_db_write', __('The cancel could not be saved to the database; the scan is still running.', 'devdome-link-monitor'), array('status' => 500));
    if ($job) {
        // The cancel flag goes up FIRST, naming exactly this job. While a tick holds the lock
        // it owns the terminal transition: it sees the flag at its next boundary (after the
        // running chunk, and again right before finalize/report) and marks the scan cancelled
        // itself, so a cancelled job can never be finalized, reported or made "latest scan".
        $flag = isset($job['job_id']) ? (string) $job['job_id'] : 'legacy';
        // update_option() also returns false when the stored value is already identical, so
        // the read-back decides whether the flag is durable.
        if (!update_option('devdlink_cancelled_at', $flag, false) && devdlink_cancelled_job_id() !== $flag) {
            return $db_error;
        }
        if (function_exists('devdlink_tick_lock_held') && devdlink_tick_lock_held()) {
            wp_clear_scheduled_hook('devdlink_run_job');
            return true;
        }
        // No tick in flight: terminalize here - the scans row (a recheck marks its own audit
        // row, never the completed scan it reuses) and the stored job, both checked. A cancel
        // that did not land is reported, never claimed.
        if (!devdlink_mark_job_scan_cancelled($job) || !devdlink_clear_job()) {
            return $db_error;
        }
    }
    wp_clear_scheduled_hook('devdlink_run_job');
    // The tick lock is deliberately NOT touched: it belongs to whichever tick holds it.
    // Deleting it here let a finishing stale tick release the NEXT job's lock.
    return true;
}

/**
 * Uncached read of the PERSISTED job. Pause/cancel/replacement is written by a DIFFERENT
 * request while a chunk is in flight; the tick's request-level settings cache would never
 * see it, so the tick must bypass the cache before its final decisions.
 *
 * @return array|null null when no job is persisted.
 */
function devdlink_persisted_job()
{
    global $wpdb;
    $table = devdlink_settings_table();
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate cache bypass: the job is written by a concurrent request.
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT setting_value FROM {$table} WHERE setting_name = %s",
        'job'
    ));
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $job = @unserialize($raw, array('allowed_classes' => false)); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- own settings store format; classes disallowed.
    return is_array($job) ? $job : null;
}

/** Uncached read of the persisted job's status ('' when no job is persisted). */
function devdlink_persisted_job_status()
{
    $job = devdlink_persisted_job();
    return ($job && isset($job['status'])) ? (string) $job['status'] : '';
}

/**
 * Uncached read of the cancel flag (the cancel comes from a DIFFERENT request). Holds the
 * job_id of the most recently cancelled job ('' when nothing was ever cancelled).
 */
function devdlink_cancelled_job_id()
{
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberate cache bypass: the flag is written by a concurrent request.
    $v = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        'devdlink_cancelled_at'
    ));
    return is_string($v) ? $v : '';
}

/** Lock option key + max lifetime (a tick can never legitimately run longer than this). */
function devdlink_lock_key()
{
    return 'devdlink_tick_lock';
}

/**
 * Acquire the single in-flight-tick lock. Atomic via add_option (false if the row already
 * exists), with a stale-lock takeover after the timeout so a crashed/timed-out tick can't
 * wedge the job forever. The stored value is "<timestamp>|<owner token>": release is
 * ownership-checked, so a stale tick that lost its lock can never delete a successor's.
 *
 * @return string|false the lock value THIS caller now owns, or false.
 */
function devdlink_acquire_tick_lock()
{
    global $wpdb;
    $key = devdlink_lock_key();
    $now = time();
    $ttl = 600; // a tick is bounded; 10 min is a generous crash window.
    $mine = $now . '|' . uniqid('t', true);

    // Atomic insert: succeeds for exactly one caller when the option does not yet exist.
    if (add_option('devdlink_tick_lock', $mine, '', 'no')) {
        return $mine;
    }

    // Lock row exists — take it over only if it is stale. (int) on "<ts>|<token>" reads the
    // leading timestamp; pre-token integer values from an older version parse the same way.
    $held_raw = get_option('devdlink_tick_lock', '');
    $held = (int) $held_raw;
    if ($held && ($now - $held) < $ttl) {
        return false; // a fresh tick is in flight.
    }
    // Stale: claim it atomically (only the UPDATE that matches the stale value wins the race).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic stale-lock takeover on the options table; values bound via prepare.
    $rows = $wpdb->query($wpdb->prepare(
        "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
        $mine,
        $key,
        (string) $held_raw
    ));
    if ($rows === 1) {
        wp_cache_delete($key, 'options');
        return $mine;
    }
    return false;
}

/**
 * Release the in-flight-tick lock — but only when it is still OURS. A holder that ran past
 * the stale TTL and was taken over must not delete the new holder's lock.
 *
 * @param string $mine the value devdlink_acquire_tick_lock() returned to this caller.
 */
function devdlink_release_tick_lock($mine)
{
    global $wpdb;
    if (!is_string($mine) || $mine === '') {
        return;
    }
    $key = devdlink_lock_key();
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ownership-checked delete; values bound via prepare.
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
        $key,
        $mine
    ));
    wp_cache_delete($key, 'options');
}

/**
 * Run ONE tick. Bounded per phase by scan_chunk_size / check_batch_size. Reschedules itself
 * until the job completes. Safe to call from cron or the REST poller — a single-holder lock
 * guarantees that two overlapping callers (the self-rescheduled cron event AND the REST
 * poller) never process the same job slice concurrently. The second caller simply returns
 * the current progress snapshot and lets the in-flight tick finish.
 */
function devdlink_run_tick()
{
    $job = devdlink_get_job();
    if (devdlink_settings_read_failed()) {
        return null; // an unreadable job is not "no job": do nothing this tick
    }
    // "finalizing" is a persisted state: a tick that died right after writing it must be
    // resumed by the next one, or the scan sits at "Finishing..." forever.
    if (!$job || !in_array($job['status'], array('running', 'finalizing'), true)) {
        return $job;
    }

    // Mutex: only one tick may be in flight at a time. A losing caller no-ops.
    $lock = devdlink_acquire_tick_lock();
    if (!$lock) {
        return $job;
    }

    try {
        // Re-read under the lock so we never act on a stale snapshot the other caller just wrote.
        $job = devdlink_get_job();
        if (!$job || !in_array($job['status'], array('running', 'finalizing'), true)) {
            return $job;
        }
        $job_id = isset($job['job_id']) ? (string) $job['job_id'] : '';

        // A deliberate backoff (the strike-separation wait) is in effect: do no work and do no
        // network requests until it expires, however aggressively the poller calls in.
        if (!empty($job['next_tick_at']) && time() < (int) $job['next_tick_at']) {
            return $job;
        }

        // The execution limit is deliberately NOT lifted here: a tick is bounded (chunk sizes, the 45s
        // wall-clock guard in devdlink_check_batch and the per-request outbound budget), so
        // it must fit inside the host's normal execution limit like any other request. A tick
        // that cannot finish is resumed by the next one — that is the whole point of the queue.
        if ($job['type'] === 'scan' || $job['type'] === 'recheck') {
            $job = devdlink_tick_scan($job);
        }
        $job['next_tick_at'] = time() + (isset($job['tick_delay']) ? max(1, (int) $job['tick_delay']) : 1);

        // A cancel may have arrived from another request while this chunk was running — honour
        // it instead of resurrecting the job from our stale copy. Matched by job_id, so a job
        // created and cancelled within the same second is still caught, and an OLDER cancel
        // can never kill a newer job.
        if ($job_id !== '' && devdlink_cancelled_job_id() === $job_id) {
            // The cancel was deferred to us (we held the lock): mark the scan cancelled and
            // clear the job, but only if OUR job is still the persisted one.
            devdlink_mark_job_scan_cancelled($job);
            // Defense in depth: if this scan had already been made "latest", fall back to the
            // newest scan that actually completed.
            if (!empty($job['scan_id']) && devdlink_get_int('last_scan_id', 0) === (int) $job['scan_id']) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans audit table.
                $prev = (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}devdlink_scans WHERE status = 'completed' AND scan_type = 'full'");
                devdlink_update_setting('last_scan_id', $prev);
            }
            $persisted = devdlink_persisted_job();
            if ($persisted && (isset($persisted['job_id']) ? (string) $persisted['job_id'] : '') === $job_id) {
                devdlink_clear_job();
            }
            return null;
        }

        // Same for a pause: it was persisted by another request mid-chunk, so keep 'paused'
        // instead of overwriting it back to 'running' (and don't reschedule below).
        if ($job['status'] === 'running' && devdlink_persisted_job_status() === 'paused') {
            $job['status'] = 'paused';
        }

        // Final ownership check: save only when the persisted job is still THIS job. A
        // replaced/cleared job means another request took over — our copy is history, and
        // saving it would clobber the successor.
        $persisted = devdlink_persisted_job();
        $persisted_id = ($persisted && isset($persisted['job_id'])) ? (string) $persisted['job_id'] : '';
        if ($persisted === null || $persisted_id !== $job_id) {
            return null;
        }

        $job['updated_at'] = time();
        if (!devdlink_save_job($job)) {
            // The persisted job is behind this one (e.g. still "finalizing" after a completed
            // finalize). Every step behind it is idempotent, so a recovery tick simply redoes
            // the transition; record it and make sure that tick comes.
            devdlink_record_error('job_save', 'The job state could not be saved; a recovery tick will redo the last step.', array('status' => (string) $job['status'], 'phase' => isset($job['phase']) ? (string) $job['phase'] : ''));
            devdlink_schedule_tick(5);
        } elseif (in_array($job['status'], array('running', 'finalizing'), true)) {
            devdlink_schedule_tick(isset($job['tick_delay']) ? (int) $job['tick_delay'] : 1);
        } else {
            // Terminal state persisted: the finalize watchdog event has nothing left to do.
            wp_clear_scheduled_hook('devdlink_run_job');
        }
    } finally {
        devdlink_release_tick_lock($lock);
    }
    return $job;
}
add_action('devdlink_run_job', 'devdlink_run_tick');

/**
 * Phase dispatcher: extract -> check -> recheck -> finalize. A 'recheck' job enters at the
 * recheck phase directly. Runs exactly ONE bounded slice and returns the mutated job.
 *
 * @param array $job
 * @return array
 */
/**
 * A database read or write failed inside a tick: the phase and cursor stay exactly where they
 * were, the error is recorded once, and the next tick (a few seconds later) retries.
 */
function devdlink_tick_db_error($job, $kind)
{
    $job['errors']     = isset($job['errors']) ? (int) $job['errors'] + 1 : 1;
    $job['tick_delay'] = 5;
    $job['message']    = __('Database error while scanning; retrying...', 'devdome-link-monitor');
    if ((int) $job['errors'] === 1) {
        devdlink_record_error('db_' . $kind, 'A database ' . ($kind === 'db_write' ? 'write' : 'read') . ' failed during the scan; the phase was kept and is being retried.', array('phase' => isset($job['phase']) ? (string) $job['phase'] : '', 'scan_id' => isset($job['scan_id']) ? (int) $job['scan_id'] : 0));
    }
    return $job;
}

function devdlink_tick_scan($job)
{
    global $wpdb;
    $links_table = $wpdb->prefix . 'devdlink_links';

    if ($job['phase'] === 'extract') {
        $chunk = max(10, min(500, devdlink_get_int('scan_chunk_size', 50)));
                $res = devdlink_extract_chunk((int) $job['scan_id'], (int) $job['cursor'], $chunk);
        $job['cursor'] = (int) $res['last_id'];
        $job['extract_done'] += (int) $res['processed'];
        if (!empty($res['error'])) {
            return devdlink_tick_db_error($job, (string) $res['error']);
        }
        if (!empty($res['done'])) {
            devdlink_db_reset_error();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table count; scan id bound via prepare.
            $pending = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$links_table} WHERE scan_id = %d AND status = 'pending'",
                (int) $job['scan_id']
            ));
            if (devdlink_db_failed()) {
                return devdlink_tick_db_error($job, 'db_read');
            }
            $job['check_total'] = $pending;
            $job['check_done'] = 0;
            $job['cursor'] = 0;
            $job['phase'] = 'check';
            $job['message'] = __('Checking links...', 'devdome-link-monitor');
        }
        return $job;
    }

    if ($job['phase'] === 'check') {
        $batch = max(3, min(50, devdlink_get_int('check_batch_size', 15)));
                $res = devdlink_check_batch((int) $job['scan_id'], 'pending', $batch);
        $job['check_done'] += (int) $res['checked'];
        if (!empty($res['error'])) {
            return devdlink_tick_db_error($job, (string) $res['error']);
        }
        if ((int) $res['remaining'] === 0) {
            // First-strike failures wait here for FRESH ticks — that is the two-strikes rule.
            devdlink_db_reset_error();
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table count; scan id bound via prepare.
            $suspects = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$links_table} WHERE scan_id = %d AND status = 'suspect'",
                (int) $job['scan_id']
            ));
            if (devdlink_db_failed()) {
                return devdlink_tick_db_error($job, 'db_read');
            }
            $job['recheck_total'] = $suspects;
            $job['recheck_done'] = 0;
            $job['phase'] = 'recheck';
            $job['message'] = __('Re-checking failures...', 'devdome-link-monitor');
        }
        return $job;
    }

    if ($job['phase'] === 'recheck') {
        $batch = max(3, min(50, devdlink_get_int('check_batch_size', 15)));
        if ($job['type'] === 'recheck') {
            // Recheck job: re-verify flagged rows IN PLACE (no destructive status reset).
            $cutoff = isset($job['args']['recheck_cutoff']) ? (string) $job['args']['recheck_cutoff'] : '';
            $res = devdlink_check_batch((int) $job['scan_id'], 'flagged', $batch, $cutoff);
        } else {
            $res = devdlink_check_batch((int) $job['scan_id'], 'suspect', $batch);
        }
                $job['recheck_done'] += (int) $res['checked'];
        $job['tick_delay'] = 1;
        if (!empty($res['error'])) {
            return devdlink_tick_db_error($job, (string) $res['error']);
        }
        if ((int) $res['remaining'] === 0) {
            $job['phase'] = 'finalize';
            $job['message'] = __('Finishing up...', 'devdome-link-monitor');
        } elseif ((int) $res['checked'] === 0 && !empty($res['wait'])) {
            // Strike two has to be an independent observation: idle until the oldest first
            // strike is old enough instead of re-checking in the same second.
            $job['tick_delay'] = max(1, min(5, (int) $res['wait'])); // 5s: the countdown stays live
            $job['message'] = sprintf(
                /* translators: 1: number of links that failed once, 2: seconds until the second check. */
                _n(
                    '%1$d link failed once. Second check in %2$ds (a link is only called broken after two separate failures)',
                    '%1$d links failed once. Second check in %2$ds (a link is only called broken after two separate failures)',
                    (int) $job['recheck_total'],
                    'devdome-link-monitor'
                ),
                (int) $job['recheck_total'],
                max(1, (int) $res['wait'])
            );
        }
        return $job;
    }

    // finalize
    if ($job['type'] === 'recheck') {
        // A recheck closes ONLY its own audit row and refreshes the current-state summary
        // (the summary recounts from the links table). The reused full scan's historical
        // roll-up is never rewritten, and last_scan_at stays the FULL scan's clock so
        // rechecks can never push a scheduled rescan into the future. It also never
        // re-sends the new-broken email: its diff against the previous full scan is the
        // SAME diff the original scan already reported.
        if (!empty($job['args']['recheck_row'])) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table update on completion.
            $recheck_written = $wpdb->update(
                $wpdb->prefix . 'devdlink_scans',
                array('status' => 'completed', 'finished_at' => current_time('mysql')),
                array('id' => (int) $job['args']['recheck_row']),
                array('%s', '%s'),
                array('%d')
            );
            if ($recheck_written === false) {
                $job['status']  = 'finalizing';
                $job['message'] = __('Finishing could not be saved; retrying...', 'devdome-link-monitor');
                $job['errors']  = isset($job['errors']) ? (int) $job['errors'] + 1 : 1;
                return $job;
            }
        }
        if (function_exists('devdlink_refresh_summary')) {
            devdlink_refresh_summary();
        }
    } else {
        // Serialized terminal transition: a cancel that arrived during the last chunk wins
        // here, before anything is finalized, reported or made "latest scan".
        $fin_job_id = isset($job['job_id']) ? (string) $job['job_id'] : '';
        if ($fin_job_id !== '' && devdlink_cancelled_job_id() === $fin_job_id) {
            $job['status'] = 'cancelled';
            return $job;
        }
        // One terminal transition: RUNNING -> FINALIZING -> COMPLETED. Once "finalizing" is
        // persisted, devdlink_cancel_job() refuses ("already finishing"), so completion and
        // cancellation can never race each other past this point.
        $job['status'] = 'finalizing';
        $job['message'] = __('Finishing...', 'devdome-link-monitor');
        if (!devdlink_save_job($job)) {
            // Not persisted: stay "running" and let the next tick try the transition again.
            $job['status']  = 'running';
            $job['message'] = __('Finishing could not be saved; retrying...', 'devdome-link-monitor');
            $job['errors']  = isset($job['errors']) ? (int) $job['errors'] + 1 : 1;
            devdlink_record_error('finalize_save', 'The finalizing state could not be saved; retrying on the next tick.', array('scan_id' => (int) $job['scan_id']));
            return $job;
        }
        // Watchdog: if this process dies inside finalize, a cron tick (or the loopback) picks
        // the persisted "finalizing" job up. A normal completion just finds nothing to do.
        devdlink_schedule_tick(5);
        $GLOBALS['devdlink_finalizing_job'] = $fin_job_id;
        $finalized = devdlink_finalize_scan((int) $job['scan_id'], true);
        unset($GLOBALS['devdlink_finalizing_job']);
        if ($finalized === false) {
            // The completed transition was refused by the database: stay in "finalizing" (the
            // next tick retries) instead of reporting a finished scan that was never recorded.
            $job['status']  = 'finalizing';
            $job['message'] = __('Finishing could not be saved; retrying...', 'devdome-link-monitor');
            $job['errors']  = isset($job['errors']) ? (int) $job['errors'] + 1 : 1;
            return $job;
        }
        if ($fin_job_id !== '' && devdlink_cancelled_job_id() === $fin_job_id) {
            $job['status'] = 'cancelled';
            return $job;
        }
        $settled = devdlink_update_setting('last_scan_id', (int) $job['scan_id'])
            && devdlink_update_setting('last_scan_at', time())
            && devdlink_update_setting('rescan_needed', 0); // the post-migration "scan once" request is satisfied
        if (!$settled) {
            // The scan row is completed and (if connected) reported - both idempotent per scan
            // id - so retrying the whole finalize from the next tick is safe and leaves no
            // half-written "latest scan".
            $job['status']  = 'finalizing';
            $job['message'] = __('Finishing could not be saved; retrying...', 'devdome-link-monitor');
            $job['errors']  = isset($job['errors']) ? (int) $job['errors'] + 1 : 1;
            devdlink_record_error('finalize_settings', 'The latest-scan settings could not be saved; retrying on the next tick.', array('scan_id' => (int) $job['scan_id']));
            return $job;
        }
        devdlink_prune_scan_history();
    }
    $job['status'] = 'completed';
    $job['message'] = __('Scan complete.', 'devdome-link-monitor');
    return $job;
}

/**
 * Create a scan session row. Returns scan id (or 0 on failure).
 *
 * @param string $scan_type full | recheck
 * @param int    $user_id
 * @return int
 */
function devdlink_create_scan($scan_type, $user_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_scans';
    $scan_type = in_array($scan_type, array('full', 'recheck'), true) ? $scan_type : 'full';
    // extractor_version records the normalization/coverage contract this scan was built with,
    // so a later release can tell a stale scan apart instead of silently mixing two models.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table insert.
    $ok = $wpdb->insert($table, array(
        'status'            => 'running',
        'scan_type'         => $scan_type,
        'extractor_version' => defined('DEVDLINK_EXTRACTOR_VERSION') ? (int) DEVDLINK_EXTRACTOR_VERSION : 0,
        'started_at'        => current_time('mysql'),
        'user_id'           => (int) $user_id,
    ), array('%s', '%s', '%d', '%s', '%d'));
    return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Finalize a scan: aggregate per-status counts into the scans row, compute the new-broken
 * diff against the previous completed full scan, refresh the cached summary and send the
 * optional email.
 *
 * @param int  $scan_id
 * @param bool $send_email false for recheck jobs (their new-broken diff was already reported)
 */
function devdlink_finalize_scan($scan_id, $send_email = true)
{
    global $wpdb;
    $scan_id = (int) $scan_id;
    $scans = $wpdb->prefix . 'devdlink_scans';
    $links = $wpdb->prefix . 'devdlink_links';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links aggregate; scan id bound via prepare.
    devdlink_db_reset_error();
    $by_status = $wpdb->get_results($wpdb->prepare(
        "SELECT status, COUNT(*) AS n FROM {$links} WHERE scan_id = %d GROUP BY status",
        $scan_id
    ));
    if (devdlink_db_failed()) {
        return false; // a failed count is not "0 links"; the caller stays finalizing and retries
    }
    $counts = array('ok' => 0, 'broken' => 0, 'redirect' => 0, 'timeout' => 0, 'blocked' => 0);
    $total_links = 0;
    foreach ((array) $by_status as $row) {
        $st = (string) $row->status;
        $total_links += (int) $row->n;
        if (isset($counts[$st])) {
            $counts[$st] = (int) $row->n;
        }
    }

    devdlink_db_reset_error();
    // New broken = broken url_hashes in THIS link set minus broken url_hashes in the previous
    // completed full scan (first scan ever: everything broken is new).
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans lookup; scan id bound via prepare.
    $prev_scan = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT MAX(id) FROM {$scans} WHERE status = 'completed' AND scan_type = 'full' AND id < %d",
        $scan_id
    ));
    if (devdlink_db_failed()) { return false; } // a failed previous-scan read must not become "first scan ever"
    devdlink_db_reset_error();
    if ($prev_scan) {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links diff aggregate; ids bound via prepare.
        $new_broken = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$links} l
             WHERE l.scan_id = %d AND l.status = 'broken'
               AND l.url_hash NOT IN (
                   SELECT url_hash FROM {$links} WHERE scan_id = %d AND status = 'broken'
               )",
            $scan_id,
            $prev_scan
        ));
    } else {
        $new_broken = (int) $counts['broken'];
    }
    if (devdlink_db_failed()) {
        return false; // previous-scan / new-broken reads failed: never record a wrong new_broken_count
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal scans table roll-up update.
    $completed = $wpdb->update($scans, array(
        'status'           => 'completed',
        'finished_at'      => current_time('mysql'),
        'total_links'      => $total_links,
        'ok_count'         => $counts['ok'],
        'broken_count'     => $counts['broken'],
        'redirect_count'   => $counts['redirect'],
        'timeout_count'    => $counts['timeout'],
        'blocked_count'    => $counts['blocked'],
        'new_broken_count' => $new_broken,
    ), array('id' => $scan_id), array('%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d'), array('%d'));
    if ($completed === false) {
        // The transition did not land: no summary, no report, no "latest scan". The caller
        // keeps the job in "finalizing" and the next tick tries again.
        return false;
    }

    // The summary feeds the cloud payload: a refresh that could not read the database must
    // not be reported. Stay finalizing (the completed row is idempotent) and retry.
    if (function_exists('devdlink_refresh_summary') && devdlink_refresh_summary() === false) {
        return false;
    }

    // A cancel that landed while this finalize was running: no report, no email.
    if (!empty($GLOBALS['devdlink_finalizing_job']) && devdlink_cancelled_job_id() === (string) $GLOBALS['devdlink_finalizing_job']) {
        return true;
    }
    if ($send_email) {
        // A connected site reports every completed full scan to DevDome (numbers only); the
        // DevDome server decides and sends the email. The plugin never composes mail itself
        // (wp.org guideline 5/6, the class that was flagged on Safe Media Cleaner).
        $cloud = function_exists('devdlink_cloud_report') ? devdlink_cloud_report($scan_id, $new_broken) : null;
        if ($new_broken > 0 && devdlink_get_int('email_new_broken', 0)) {
            if (is_array($cloud) && !empty($cloud['emailed'])) {
                $conn = function_exists('devdcorev1_connection_state') ? devdcorev1_connection_state() : array();
                devdlink_update_setting('email_reported_scan_id', $scan_id);
                devdlink_update_setting('email_last_status', 'sent');
                devdlink_update_setting('email_last_scan_id', $scan_id);
                devdlink_update_setting('email_last_at', time());
                devdlink_update_setting('email_last_to', isset($conn['email']) ? (string) $conn['email'] : '');
            } elseif (devdlink_get_int('email_reported_scan_id', 0) !== $scan_id) {
                // Not connected or DevDome unreachable: no email. Recorded so the Dashboard says so.
                devdlink_update_setting('email_last_status', 'failed');
                devdlink_update_setting('email_last_scan_id', $scan_id);
                devdlink_update_setting('email_last_at', time());
            }
        }
    }
    return true;
}

/**
 * Retention: every full scan creates a fresh copy of the site's whole link set, so without
 * pruning the links/link_sources tables grow forever. Keep the link sets of the TWO newest
 * completed full scans (current state + the set the new-broken diff compares against) and
 * delete everything older in bounded batches. Scan AUDIT rows are tiny; the newest 50 are
 * kept as history. Called once per completed full scan; a large backlog drains across runs.
 */
function devdlink_prune_scan_history()
{
    global $wpdb;
    $scans   = $wpdb->prefix . 'devdlink_scans';
    $links   = $wpdb->prefix . 'devdlink_links';
    $sources = $wpdb->prefix . 'devdlink_link_sources';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL.
    $keep = $wpdb->get_col("SELECT id FROM {$scans} WHERE status = 'completed' AND scan_type = 'full' ORDER BY id DESC LIMIT 2");
    $keep = array_map('intval', (array) $keep);
    if (count($keep) < 2) {
        return; // fewer than two completed full scans: nothing is old enough to prune.
    }
    // One %d placeholder per id, and the ids passed as prepare() arguments. Casting to
    // int and interpolating is not enough: every value in an IN list goes through
    // prepare(), which is what the directory guidelines require.
    $keep_ph = implode(',', array_fill(0, count($keep), '%d'));

    for ($i = 0; $i < 5; $i++) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; pruning cannot be cached.
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $links is an internal prefix table; $keep_ph is a generated %d list.
                "SELECT id FROM {$links} WHERE scan_id NOT IN ({$keep_ph}) ORDER BY id ASC LIMIT 2000",
                $keep
            )
        );
        if (empty($ids)) {
            break;
        }
        $ids = array_map('intval', (array) $ids);
        $in_ph = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; pruning cannot be cached.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $sources is an internal prefix table; $in_ph is a generated %d list.
                "DELETE FROM {$sources} WHERE link_id IN ({$in_ph})",
                $ids
            )
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; pruning cannot be cached.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $links is an internal prefix table; $in_ph is a generated %d list.
                "DELETE FROM {$links} WHERE id IN ({$in_ph})",
                $ids
            )
        );
    }

    // Audit-row cap: keep the newest 50 scans rows; only rows whose links are already gone
    // are deleted, so an unfinished prune backlog is never orphaned.
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table, constant SQL.
    $cut = (int) $wpdb->get_var("SELECT MIN(id) FROM (SELECT id FROM {$scans} ORDER BY id DESC LIMIT 50) x");
    if ($cut) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; pruning cannot be cached.
        $wpdb->query(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal prefix tables; $keep_ph is a generated %d list.
                "DELETE FROM {$scans} WHERE id < %d AND id NOT IN ({$keep_ph})
                 AND NOT EXISTS (SELECT 1 FROM {$links} WHERE {$links}.scan_id = {$scans}.id)",
                array_merge(array($cut), $keep)
            )
        );
    }
}

/**
 * Progress snapshot for the poller. Percent is phase-aware and monotonic:
 * full scan — extract 0-30, check 30-92, recheck 92-97, finalize 98, done 100;
 * recheck job — recheck 0-95, finalize 98, done 100.
 * processed/total reflect the CURRENT phase's counters (extract shows posts, check/recheck
 * show links). ETA only during extract/check.
 */
function devdlink_job_progress()
{
    $job = devdlink_get_job();
    if (!$job) {
        return array('active' => false, 'status' => 'idle');
    }

    $phase = (string) $job['phase'];
    if ($phase === 'extract') {
        $done = (int) $job['extract_done'];
        $total = (int) $job['extract_total'];
    } elseif ($phase === 'check') {
        $done = (int) $job['check_done'];
        $total = (int) $job['check_total'];
    } elseif ($phase === 'recheck') {
        $done = (int) $job['recheck_done'];
        $total = (int) $job['recheck_total'];
    } else { // finalize
        $done = 0;
        $total = 0;
    }
    if ($total > 0 && $done > $total) {
        $done = $total;
    }
    $ratio = $total > 0 ? min(1, $done / $total) : 0;

    if ($job['status'] === 'completed') {
        $pct = 100;
    } elseif ($job['type'] === 'recheck') {
        $pct = $phase === 'finalize' ? 98 : min(95, (int) round(95 * $ratio));
    } elseif ($phase === 'extract') {
        $pct = min(30, (int) round(30 * $ratio));
    } elseif ($phase === 'check') {
        $pct = min(92, 30 + (int) round(62 * $ratio));
    } elseif ($phase === 'recheck') {
        $pct = min(97, 92 + (int) round(5 * $ratio));
    } else { // finalize
        $pct = 98;
    }

    $message = (string) $job['message'];
    if ($message === '' && $job['status'] === 'running') {
        $message = __('Working...', 'devdome-link-monitor');
    }

    $eta = null;
    if ($job['status'] === 'running' && $job['type'] === 'scan'
        && in_array($phase, array('extract', 'check'), true)
        && $done > 0 && $total > $done) {
        $elapsed = max(1, time() - (int) $job['created_at']);
        $rate = $done / $elapsed;
        if ($rate > 0) {
            $eta = (int) round(($total - $done) / $rate);
        }
    }

    return array(
        'active'    => in_array($job['status'], array('running', 'paused'), true),
        'type'      => $job['type'],
        'status'    => $job['status'],
        'phase'     => $phase,
        'processed' => $done,
        'total'     => $total,
        'percent'   => $pct,
        'errors'    => (int) $job['errors'],
        'eta'       => $eta,
        'scan_id'   => (int) $job['scan_id'],
        'message'   => $message,
    );
}
