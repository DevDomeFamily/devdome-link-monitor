<?php
/**
 * 404 Monitor admin surface — the 404 Log tab shell (data loads via REST GET /404s
 * from lm-admin.js), the dashboard 404 Monitor block, and the two data helpers
 * (counts + paginated rows) that the summary refresh and rest.php delegate to.
 */

defined('ABSPATH') || exit;

/* ---------------------------------------------------------------------------
 * Data helpers (own the 404 SQL — rest.php and refresh_summary call these).
 * ------------------------------------------------------------------------- */

/**
 * Aggregate 404 counters.
 *
 * @return array array('paths' => int, 'human' => int, 'bot' => int, 'ignored' => int)
 */
function devdlink_404_counts()
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_404s';

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- aggregate counts over the internal prefixed 404 table; no user input; live counters must not be cached.
    $row = $wpdb->get_row(
        "SELECT
            COALESCE(SUM(ignored = 0), 0) AS paths,
            COALESCE(SUM(IF(ignored = 0, human_hits, 0)), 0) AS human,
            COALESCE(SUM(IF(ignored = 0, bot_hits, 0)), 0) AS bot,
            COALESCE(SUM(ignored = 1), 0) AS ignored_rows
         FROM {$table}",
        ARRAY_A
    );

    return array(
        'paths'   => isset($row['paths']) ? (int) $row['paths'] : 0,
        'human'   => isset($row['human']) ? (int) $row['human'] : 0,
        'bot'     => isset($row['bot']) ? (int) $row['bot'] : 0,
        'ignored' => isset($row['ignored_rows']) ? (int) $row['ignored_rows'] : 0,
    );
}

/**
 * Paginated 404 rows for the REST endpoint (and any server-side render).
 *
 * @param array $args page, per_page, humans_only, show_ignored, orderby, order.
 * @return array array('total' => int, 'rows' => array) rows as associative arrays.
 */
function devdlink_404_rows($args)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_404s';

    $args = wp_parse_args((array) $args, array(
        'page'         => 1,
        'per_page'     => 25,
        'humans_only'  => 0,
        'show_ignored' => 0,
        'ignored_only' => 0,
        'orderby'      => 'last_seen',
        'order'        => 'desc',
        'search'       => '',
    ));

    $page     = max(1, (int) $args['page']);
    $per_page = max(1, min(200, (int) $args['per_page']));
    $offset   = ($page - 1) * $per_page;

    // Order columns are allowlisted literals — never raw input.
    $orderby_map = array(
        'human_hits' => 'human_hits',
        'bot_hits'   => 'bot_hits',
        'last_seen'  => 'last_seen',
    );
    $orderby = isset($orderby_map[$args['orderby']]) ? $orderby_map[$args['orderby']] : 'last_seen';
    $order   = ('asc' === strtolower((string) $args['order'])) ? 'ASC' : 'DESC';

    // WHERE pieces are fixed literals (the toggles pick which one); every value, the toggle
    // state included, is bound as a placeholder so the WHOLE query goes through one prepare().
    // "Ignored" pill = ONLY the ignored paths (owner 2026-08-25); show_ignored = everything,
    // i.e. both flag states (the column is NOT NULL and only ever written 0 or 1).
    if ($args['ignored_only']) {
        $where  = array('ignored = %d');
        $params = array(1);
    } elseif ($args['show_ignored']) {
        $where  = array('ignored IN (%d, %d)');
        $params = array(0, 1);
    } else {
        $where  = array('ignored = %d');
        $params = array(0);
    }
    if ($args['humans_only']) {
        $where[] = 'human_hits > 0';
    }
    $search = trim((string) $args['search']);
    if ($search !== '') {
        $where[]  = 'path LIKE %s';
        $params[] = '%' . $wpdb->esc_like($search) . '%';
    }
    $where_sql = implode(' AND ', $where);

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- internal prefixed 404 table; the placeholders live inside $where_sql and every value is bound via prepare; live admin listing must not be cached.
    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $params));

    $params[] = $per_page;
    $params[] = $offset;
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- internal prefixed 404 table; the placeholders live inside $where_sql and every value is bound via prepare; ORDER BY column and direction are allowlisted literals from $orderby_map; live admin listing must not be cached.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, path, human_hits, bot_hits, last_referrer, last_ua, ignored, first_seen, last_seen
         FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id DESC LIMIT %d OFFSET %d",
        $params
    ), ARRAY_A);

    return array(
        'total' => $total,
        'rows'  => $rows ? $rows : array(),
    );
}

/* ---------------------------------------------------------------------------
 * Render helpers.
 * ------------------------------------------------------------------------- */

/** Nonced URL of the 404-log CSV export (handled by devdlink_handle_export_404). */
function devdlink_fof_export_url()
{
    return wp_nonce_url(
        add_query_arg(
            array(
                'page'          => DEVDLINK_PAGE,
                'lm_export_404' => '1',
            ),
            admin_url('admin.php')
        ),
        'devdlink_export_404',
        '_lmexp'
    );
}

/* ---------------------------------------------------------------------------
 * Dashboard block (Tool 2 card on the Dashboard tab).
 * ------------------------------------------------------------------------- */

/** The "404 Monitor" dashboard block — stats pills from the cached summary + actions. */
function devdlink_render_404_block()
{
    if (function_exists('devdlink_hub_summary')) {
        $s       = devdlink_hub_summary();
        $paths   = isset($s['fof_paths']) ? (int) $s['fof_paths'] : 0;
        $human   = isset($s['fof_human_hits']) ? (int) $s['fof_human_hits'] : 0;
        $bot     = isset($s['fof_bot_hits']) ? (int) $s['fof_bot_hits'] : 0;
        $ignored = isset($s['fof_ignored']) ? (int) $s['fof_ignored'] : 0;
    } else {
        $c       = devdlink_404_counts();
        $paths   = $c['paths'];
        $human   = $c['human'];
        $bot     = $c['bot'];
        $ignored = $c['ignored'];
    }
    ?>
    <div class="dd-card" style="margin-bottom:20px;">
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:24px;">
            <div style="flex:1;min-width:260px;">
                <h2 class="dd-h2" style="margin:0 0 4px;"><?php esc_html_e('404 Monitor', 'devdome-link-monitor'); ?></h2>
                <p style="color:#6b7280;margin:0 0 14px;max-width:520px;"><?php esc_html_e('Every 404 is logged automatically, split by user agent into known crawlers and everything else. Ignore the noise, export the log, or turn a hot 404 into a redirect.', 'devdome-link-monitor'); ?></p>
                <div class="lm-toolbar lm-actions" style="margin:0;">
                    <a class="dd-btn dd-btn-primary" href="#fof"><span class="dashicons dashicons-warning"></span> <?php esc_html_e('View 404 Log', 'devdome-link-monitor'); ?></a>
                    <a class="dd-btn" href="<?php echo esc_url(devdlink_fof_export_url()); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e('Export CSV', 'devdome-link-monitor'); ?></a>
                </div>
            </div>
        </div>
        <div class="dd-stats" style="margin-top:18px;grid-template-columns:repeat(4,1fr);">
            <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) $paths; ?></div><div class="dd-stat-lbl"><?php esc_html_e('404 paths', 'devdome-link-monitor'); ?></div></div>
            <div class="dd-stat"><div class="dd-stat-num"<?php echo $human > 0 ? ' style="color:#ef4444;"' : ''; ?>><?php echo (int) $human; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Human hits', 'devdome-link-monitor'); ?></div></div>
            <div class="dd-stat"><div class="dd-stat-num" style="color:#6b7280;"><?php echo (int) $bot; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Bot hits', 'devdome-link-monitor'); ?></div></div>
            <div class="dd-stat"><div class="dd-stat-num"><?php echo (int) $ignored; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Ignored', 'devdome-link-monitor'); ?></div></div>
        </div>
    </div>
    <?php
}

/* ---------------------------------------------------------------------------
 * 404 Log tab.
 * ------------------------------------------------------------------------- */

/**
 * The 404 Log tab body — pure shell on the dd-app design system; lm-admin.js
 * populates #lm-fof-tbody / #lm-fof-pager via REST GET /404s and wires the
 * humans-only / show-ignored toggles and per-row actions.
 */
function devdlink_render_404_tab($s = null)
{
    if (!is_array($s)) {
        $s = devdlink_hub_summary();
    }
    ?>
    <div class="max-w-5xl px-6 py-6">
        <section>
        <div class="dd-card">
            <?php devdlink_render_404_stats($s, 'dd-stats lm-ov-stats'); ?>
            <?php // Row 1: pills (All / Humans only / Ignored) + Columns; row 2: selection, Actions, Apply. DESIGN.md section 19. ?>
            <div class="lm-toolbar" role="group" aria-label="<?php esc_attr_e('Filter 404s', 'devdome-link-monitor'); ?>" style="margin:18px 0 12px;">
                <div class="lm-pills">
                    <?php foreach (array('all' => __('All', 'devdome-link-monitor'), 'humans' => __('Seen by humans', 'devdome-link-monitor'), 'ignored' => __('Ignored', 'devdome-link-monitor')) as $k => $label) : $on = ($k === 'all'); ?>
                        <button type="button" class="lm-pill lm-fof-filter<?php echo $on ? ' is-on' : ''; ?>" data-filter="<?php echo esc_attr($k); ?>" aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?> <span class="lm-pill-num" data-lm-fof-count="<?php echo esc_attr($k); ?>">0</span></button>
                    <?php endforeach; ?>
                    <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Every 404 on your site, logged automatically and split by user agent. A user agent can be forged, so "human" means "did not match a known crawler pattern". Ignored paths are never logged again.', 'devdome-link-monitor'); ?></span></span>
                </div>
                <span style="flex:1;"></span>
                <div class="lm-cols">
                    <button type="button" class="dd-btn dd-btn-sm" id="lm-fof-cols-btn" aria-haspopup="true" aria-expanded="false"><span class="dashicons dashicons-editor-table"></span> <?php esc_html_e('Columns', 'devdome-link-monitor'); ?></button>
                    <div class="lm-cols-panel" id="lm-fof-cols-panel" style="display:none;">
                        <?php foreach (array('referrer' => __('Referrer', 'devdome-link-monitor'), 'seen' => __('Last seen', 'devdome-link-monitor'), 'humans' => __('Human hits', 'devdome-link-monitor'), 'bots' => __('Bot hits', 'devdome-link-monitor')) as $ck => $cl) : ?>
                            <label class="lm-cols-row"><input type="checkbox" class="dd-check" data-col="<?php echo esc_attr($ck); ?>" checked> <?php echo esc_html($cl); ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="lm-toolbar lm-actions" style="margin:0 0 14px;">
                <span class="lm-selcount" id="lm-fof-selcount" aria-live="polite"><?php
                    /* translators: %d is the number of selected 404 paths. */
                    echo esc_html(sprintf(__('Paths Selected (%d)', 'devdome-link-monitor'), 0)); ?></span>
                <?php devdlink_render_dropdown('lm_fof_action', '', array(
                    ''         => __('Actions', 'devdome-link-monitor'),
                    'ignore'   => __('Ignore selected', 'devdome-link-monitor'),
                    'unignore' => __('Stop ignoring selected', 'devdome-link-monitor'),
                    'delete'   => __('Delete selected', 'devdome-link-monitor'),
                ), __('Actions', 'devdome-link-monitor')); ?>
                <button type="button" class="dd-btn dd-btn-primary" id="lm-fof-apply" disabled><?php esc_html_e('Apply', 'devdome-link-monitor'); ?></button>
                <span style="flex:1;"></span>
                <label class="screen-reader-text" for="lm-fof-search"><?php esc_html_e('Search path', 'devdome-link-monitor'); ?></label>
                <input type="search" id="lm-fof-search" class="dd-input" style="width:220px;" placeholder="<?php esc_attr_e('Search path...', 'devdome-link-monitor'); ?>">
            </div>
            <div id="lm-fof-app">
                <div class="dd-list-wrap">
                    <table class="dd-table dd-list" style="width:100%;">
                        <caption class="screen-reader-text"><?php esc_html_e('Requested paths that returned 404, with hit counts by user-agent class', 'devdome-link-monitor'); ?></caption>
                        <thead>
                            <tr>
                                <th class="dd-th lm-col-check"><input type="checkbox" class="dd-check" id="lm-fof-check-all" aria-label="<?php esc_attr_e('Select all paths on this page', 'devdome-link-monitor'); ?>"></th>
                                <th class="dd-th" data-col="path"><?php esc_html_e('URL', 'devdome-link-monitor'); ?></th>
                                <th class="dd-th th-sort sorted-desc" data-col="humans" data-sort="human_hits" role="button" tabindex="0" aria-sort="descending" title="<?php esc_attr_e('Sort by human hits', 'devdome-link-monitor'); ?>"><?php esc_html_e('Human hits', 'devdome-link-monitor'); ?><span class="th-sort-ind"></span></th>
                                <th class="dd-th th-sort" data-col="bots" data-sort="bot_hits" role="button" tabindex="0" aria-sort="none" title="<?php esc_attr_e('Sort by bot hits', 'devdome-link-monitor'); ?>"><?php esc_html_e('Bot hits', 'devdome-link-monitor'); ?><span class="th-sort-ind"></span></th>
                            </tr>
                        </thead>
                        <tbody id="lm-fof-tbody">
                            <tr><td class="dd-td" colspan="4" style="color:#6b7280;"><?php esc_html_e('Loading...', 'devdome-link-monitor'); ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <div id="lm-fof-empty" class="dd-empty" style="display:none;"><?php esc_html_e('No 404s logged yet. That is a good thing.', 'devdome-link-monitor'); ?></div>
                <div id="lm-fof-pager" class="lm-pagination"></div>
            </div>
        </div>
        </section>
    </div>
    <?php
}
