<?php
/**
 * Admin: menu, scoped enqueue, settings save handler (PRG, nonce + cap), and the tabbed
 * dashboard rendered 1:1 on the shared `.dd-app` design system. Tabs: Overview (Link Health
 * block + 404 Monitor block), Broken Links (review table powered by lm-admin.js + REST),
 * 404 Log (rendered by LANE B), Settings.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/devdome-tools-menu.php';

function devdlink_menu()
{
    add_submenu_page(
        defined('DEVDCOREV1_TOOLS_MENU_SLUG') ? DEVDCOREV1_TOOLS_MENU_SLUG : 'devdcorev1-tools',
        'DevDome Link Monitor',
        'Link Monitor',
        devdlink_capability('view'),
        DEVDLINK_PAGE,
        'devdlink_render_page',
        6
    );
}
add_action('admin_menu', 'devdlink_menu');

function devdlink_enqueue_assets($hook)
{
    if (strpos($hook, DEVDLINK_PAGE) === false) {
        return;
    }
    $css = DEVDLINK_DIR . 'assets/devdome-tools-tw.css';
    $ver = file_exists($css) ? filemtime($css) : DEVDLINK_VERSION;
    wp_enqueue_style('devdlink-ui', DEVDLINK_URL . 'assets/devdome-tools-tw.css', array(), $ver);
    wp_enqueue_style('dashicons');

    $js = DEVDLINK_DIR . 'assets/lm-admin.js';
    $jver = file_exists($js) ? filemtime($js) : DEVDLINK_VERSION;
    wp_enqueue_script('devdlink-admin', DEVDLINK_URL . 'assets/lm-admin.js', array(), $jver, true);

    wp_localize_script('devdlink-admin', 'DevdLink', array(
        'root'  => esc_url_raw(rest_url('devdlink/v1/')),
        'home'  => esc_url_raw(home_url('/')),
        'nonce' => wp_create_nonce('wp_rest'),
        'i18n'  => array(
            'healthy'        => __('Healthy', 'devdome-link-monitor'),
            'broken'         => __('Broken', 'devdome-link-monitor'),
            /* translators: %d is the number of excluded domains. */
            'domainsCount'   => __('Domains (%d)', 'devdome-link-monitor'),
            'page'           => __('Page', 'devdome-link-monitor'),
            /* translators: 1: first row number, 2: last row number, 3: total rows. */
            'ofTotal'        => __('%1$s-%2$s of %3$s', 'devdome-link-monitor'),
            /* translators: %d is the number of posts, pages or products the link was found in. */
            'editHintMany'   => __('Saving rewrites this link in the %d item(s) above. Only exact matches are changed; WordPress keeps a revision of each one.', 'devdome-link-monitor'),
            'editHintNone'   => __('This link was not found in any content at the last scan; saving only updates the tracked record.', 'devdome-link-monitor'),
            'view'           => __('View', 'devdome-link-monitor'),
            'copyUrl'        => __('Copy URL', 'devdome-link-monitor'),
            /* translators: %d is the number of selected links. */
            'linksSelected'  => __('Links Selected (%d)', 'devdome-link-monitor'),
            /* translators: %d is the number of selected 404 paths. */
            'pathsSelected'  => __('Paths Selected (%d)', 'devdome-link-monitor'),
            'referrer'       => __('Referrer', 'devdome-link-monitor'),
            'suggestion'     => __('Suggestion', 'devdome-link-monitor'),
            'lastSeen'       => __('Last seen', 'devdome-link-monitor'),
            'firstSeen'      => __('First seen', 'devdome-link-monitor'),
            'selectPath'     => __('Select this path', 'devdome-link-monitor'),
            /* translators: %d is the number of 404 entries about to be deleted. */
            'confirmDeleteMany' => __('Delete %d 404 entries?', 'devdome-link-monitor'),
            'copied'         => __('Copied', 'devdome-link-monitor'),
            'redirect'       => __('Redirect', 'devdome-link-monitor'),
            'findRedirect'   => __('Find redirect', 'devdome-link-monitor'),
            'finding'        => __('Looking...', 'devdome-link-monitor'),
            'noMatch'        => __('No close match among published pages.', 'devdome-link-monitor'),
            'createRedirect' => __('Create redirect', 'devdome-link-monitor'),
            'copyHtaccess'   => __('Copy .htaccess rule', 'devdome-link-monitor'),
            /* translators: %d is the number of hidden pages. */
            'showMore'       => __('Show %d more', 'devdome-link-monitor'),
            'showLess'       => __('Show less', 'devdome-link-monitor'),
            'edit'           => __('Edit', 'devdome-link-monitor'),
            'nowhere'        => __('Not found in any post, page or product.', 'devdome-link-monitor'),
            'editSame'       => __('That is the current URL.', 'devdome-link-monitor'),
            'editInvalid'    => __('Enter a full http(s) URL.', 'devdome-link-monitor'),
            'saving'         => __('Saving...', 'devdome-link-monitor'),
            'save'           => __('Save', 'devdome-link-monitor'),
            // Short badge words for the thin Status column (the long labels stay in CSV/tooltips).
            'badge'          => array(
                'pending'   => __('Queued', 'devdome-link-monitor'),
                'suspect'   => __('Re-checking', 'devdome-link-monitor'),
                'ok'        => __('OK', 'devdome-link-monitor'),
                'broken'    => __('Broken', 'devdome-link-monitor'),
                'redirect'  => __('Redirect', 'devdome-link-monitor'),
                'timeout'   => __('Unverified', 'devdome-link-monitor'),
                'blocked'   => __('Blocked', 'devdome-link-monitor'),
                'dismissed' => __('Dismissed', 'devdome-link-monitor'),
            ),
            'perPage'        => __('Show per page:', 'devdome-link-monitor'),
            'anchor'         => __('Anchor', 'devdome-link-monitor'),
            'image'          => __('image', 'devdome-link-monitor'),
            'hop'            => __('hop', 'devdome-link-monitor'),
            'hops'           => __('hops', 'devdome-link-monitor'),
            'selectRow'      => __('Select this link', 'devdome-link-monitor'),
            /* translators: %d is the number of links about to be unlinked. */
            'confirmUnlinkMany' => __('Remove %d links but keep their anchor text?', 'devdome-link-monitor'),
            /* translators: %d is the number of links about to be dismissed. */
            'confirmDismissMany' => __('Dismiss %d links?', 'devdome-link-monitor'),
            'scanning'       => __('Scanning...', 'devdome-link-monitor'),
            'starting'       => __('Starting...', 'devdome-link-monitor'),
            'completed'      => __('Completed', 'devdome-link-monitor'),
            'pause'          => __('Pause', 'devdome-link-monitor'),
            'resume'         => __('Resume', 'devdome-link-monitor'),
            'pausing'        => __('Pausing after the current batch...', 'devdome-link-monitor'),
            'loading'        => __('Loading...', 'devdome-link-monitor'),
            'noResults'      => __('No links matched this filter.', 'devdome-link-monitor'),
            'noFof'          => __('No 404s logged yet. That is a good thing.', 'devdome-link-monitor'),
            'rechecking'     => __('Checking...', 'devdome-link-monitor'),
            'recheck'        => __('Re-check', 'devdome-link-monitor'),
            'editUrl'        => __('Edit URL', 'devdome-link-monitor'),
            'unlink'         => __('Unlink', 'devdome-link-monitor'),
            'dismiss'        => __('Dismiss', 'devdome-link-monitor'),
            'ignore'         => __('Ignore', 'devdome-link-monitor'),
            'unignore'       => __('Unignore', 'devdome-link-monitor'),
            'delete'         => __('Delete', 'devdome-link-monitor'),
            'createRedirect' => __('Create redirect', 'devdome-link-monitor'),
            'copyHtaccess'   => __('Copy .htaccess', 'devdome-link-monitor'),
            'copied'         => __('Copied', 'devdome-link-monitor'),
            'editPrompt'     => __('New URL (updates every post containing this link):', 'devdome-link-monitor'),
            'confirmUnlink'  => __('Remove this link from every post but keep the anchor text?', 'devdome-link-monitor'),
            'confirmDismiss' => __('Dismiss this link? It will be hidden from the review list until the next scan.', 'devdome-link-monitor'),
            'confirmDelete'  => __('Delete this 404 entry? It will be logged again on the next hit.', 'devdome-link-monitor'),
            'couldNotStart'  => __('Could not start.', 'devdome-link-monitor'),
            'actionFailed'   => __('That did not work. Please try again.', 'devdome-link-monitor'),
            'paused'         => __('Paused', 'devdome-link-monitor'),
            'cancelled'      => __('Cancelled', 'devdome-link-monitor'),
            'abandoned'      => __('The previous scan was abandoned and has been closed.', 'devdome-link-monitor'),
            'conflict'       => __('Another job is already running.', 'devdome-link-monitor'),
            'stale'          => __('The content changed since the last scan. Re-scan and try again.', 'devdome-link-monitor'),
            'networkError'   => __('The server could not be reached. Check your connection and try again.', 'devdome-link-monitor'),
            'noPermission'   => __('You do not have permission to do that.', 'devdome-link-monitor'),
            /* translators: %d is the number of posts that were updated. */
            'postsUpdated'   => __('Updated %d post(s).', 'devdome-link-monitor'),
            'pageOf'         => __('Page', 'devdome-link-monitor'),
            'items'          => __('items', 'devdome-link-monitor'),
            'prev'           => __('Prev', 'devdome-link-monitor'),
            'next'           => __('Next', 'devdome-link-monitor'),
        ),
    ));

    wp_add_inline_style('devdlink-ui', devdlink_inline_css());
    wp_add_inline_script('devdlink-admin', devdlink_inline_js());
}
add_action('admin_enqueue_scripts', 'devdlink_enqueue_assets');

/** Page-scoped CSS on top of the shared stylesheet (attached via wp_add_inline_style). */
function devdlink_inline_css()
{
    return <<<'CSS'
/* This plugin's tw build ships a scoped preflight (.dd-app h1..h6 { font-size:inherit;
   font-weight:inherit }) that SMC/VC's builds lack; its 0,1,1 specificity silently beat
   .text-xl/.font-bold on the page title (owner 2026-08-20: "title must match the rest").
   Match the other plugins' 20px/700 with a higher-specificity page rule. */
.dd-app h1.text-xl { font-size:1.25rem; line-height:1.75rem; font-weight:700; }
/* DESIGN.md section 15 canonical connect button (BLUE, not the indigo save). */
.dd-app .lm-connect-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; font-size:14px; font-weight:600; border-radius:8px; padding:10px 20px; line-height:1; white-space:nowrap; color:#fff; background:#2563eb; border:1px solid #2563eb; box-shadow:0 4px 10px -3px rgba(37,99,235,.5); text-decoration:none; transition:all .12s; }
.dd-app .lm-connect-btn:hover { background:#1d4ed8; border-color:#1d4ed8; color:#fff; }
/* Header account pill (SMC "Recycle Bin protected" twin) + the overview dashboard. */
.dd-app a.dd-pill { text-decoration:none; }
.dd-app .lm-ov { display:grid; grid-template-columns:minmax(220px,250px) minmax(0,1fr); gap:16px; margin-bottom:16px; }
@media (max-width:782px) { .dd-app .lm-ov { grid-template-columns:1fr; } }
.dd-app .lm-ov-side { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:14px; }
.dd-app .lm-ov-cards { display:grid; gap:16px; min-width:0; }
.dd-app .lm-ov-cards .dd-sec-head { margin-bottom:1rem; }
.dd-app .lm-ov-link { margin-left:auto; font-size:12px; font-weight:600; color:#4f46e5; text-decoration:none; white-space:nowrap; }
.dd-app .lm-ov-link:hover { color:#4338ca; }
.dd-app .lm-ov-stats { grid-template-columns:repeat(auto-fit,minmax(110px,1fr)); gap:10px; }
.dd-app .lm-ov-stats .dd-stat { padding:12px 14px; }
.dd-app .lm-ov-stats .dd-stat-num { font-size:22px; }
.dd-app .lm-bottom { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px; }
/* Trend chart = DESIGN.md section 17 (.dd-trend): stacked good/bad columns + the Analytics dashboard tooltip. */
.dd-app .dd-trend { position:relative; padding-top:90px; } /* headroom: the tooltip sits above the column, never over the card title */
.dd-app .dd-trend-bars { display:flex; align-items:flex-end; gap:4px; height:64px; }
.dd-app .dd-trend-col { flex:1; max-width:40px; height:100%; display:flex; flex-direction:column; justify-content:flex-end; cursor:default; outline:none; }
.dd-app .dd-trend-col i { display:block; }
.dd-app .dd-trend-col.is-empty { pointer-events:none; }
.dd-app .dd-trend-empty { height:2px; background:#e5e7eb; border-radius:2px; }
.dd-app .dd-trend-col .dd-trend-bad { background:#ef4444; border-radius:2px 2px 0 0; }
.dd-app .dd-trend-col .dd-trend-ok { background:#10b981; border-radius:0 0 2px 2px; }
.dd-app .dd-trend-col:hover i, .dd-app .dd-trend-col:focus i { filter:brightness(.9); }
.dd-app .dd-trend-tip { opacity:0; position:absolute; top:0; left:0; background:rgba(255,255,255,.92); color:#3c4043; border:1px solid #dadce0; border-radius:4px; pointer-events:none; transform:translate(-50%,-100%); transition:opacity .1s ease; z-index:100; box-shadow:0 2px 8px rgba(0,0,0,.1); padding:10px 12px; font-size:12px; min-width:150px; white-space:nowrap; }
.dd-app .dd-trend-tip.is-on { opacity:1; }
.dd-app .dd-trend-date { font-weight:400; color:#202124; font-size:14px; margin-bottom:4px; display:block; }
.dd-app .dd-trend-row { display:flex; justify-content:space-between; align-items:center; gap:16px; font-size:13px; color:#3c4043; }
.dd-app .dd-trend-row > span:first-child { display:flex; align-items:center; gap:8px; }
.dd-app .dd-trend-swatch { width:10px; height:10px; border-radius:2px; display:block; }
.dd-app .dd-trend-val { font-weight:600; }
.dd-app .lm-kv { display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px solid #f3f4f6; font-size:13px; }
.dd-app .lm-kv:last-child { border-bottom:0; }
.dd-app .lm-kv > span:first-child { color:#6b7280; }
.dd-app .lm-kv > span:last-child { font-weight:600; color:#111827; text-align:right; }
.dd-app .lm-list-count { font-size:13px; font-weight:600; color:#374151; margin:0 0 6px; }
.dd-app .lm-list-count[hidden] { display:none; }
.dd-app .lm-kv > span.lm-on { color:#059669; }
.dd-app .lm-kv > span.lm-off { color:#9ca3af; }
.dd-app .lm-kv > span.lm-bad { color:#dc2626; }
/* Tabs: active = underline only, never a focus square lingering after a click
   (same rule as Safe Media Cleaner; owner 2026-08-21 "no box, only underlined"). */
.dd-app .dd-tab:focus, .dd-app .dd-tab:focus-visible { outline:none; box-shadow:none; }
.dd-app .lm-toolbar { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:16px; }
/* Numbers are typed, not clicked - hide the +/- spinners everywhere (SMC rule 1:1). */
.dd-app input[type=number]::-webkit-outer-spin-button, .dd-app input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
.dd-app input[type=number] { -moz-appearance:textfield; appearance:textfield; }
/* Custom dropdown + tooltip components, COPIED COMPILED from the RM build (DESIGN.md
   sections 4/6): LM's own tw build purged them because the markup never used them. */
.dd-app .dd-dd-trigger, .dd-app .dd-dd-panel { border-style:solid; }
.dd-app .dd-dd{position:relative;display:inline-block}
.dd-app .dd-dd-trigger{display:flex;height:34px;width:100%;cursor:pointer;-webkit-user-select:none;-moz-user-select:none;user-select:none;align-items:center;justify-content:space-between;gap:.5rem;border-radius:.5rem;border-width:1px;--tw-border-opacity:1;border-color:rgb(156 163 175/var(--tw-border-opacity,1));--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1));padding:.375rem .75rem;font-size:13px;font-weight:600;--tw-text-opacity:1;color:rgb(55 65 81/var(--tw-text-opacity,1));--tw-shadow:0 1px 3px 0 rgba(0,0,0,.1),0 1px 2px -1px rgba(0,0,0,.1);--tw-shadow-colored:0 1px 3px 0 var(--tw-shadow-color),0 1px 2px -1px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow);transition-property:all;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}
.dd-app .dd-dd.is-open .dd-dd-trigger{--tw-border-opacity:1;border-color:rgb(99 102 241/var(--tw-border-opacity,1));--tw-ring-offset-shadow:var(--tw-ring-inset) 0 0 0 var(--tw-ring-offset-width) var(--tw-ring-offset-color);--tw-ring-shadow:var(--tw-ring-inset) 0 0 0 calc(2px + var(--tw-ring-offset-width)) var(--tw-ring-color);box-shadow:var(--tw-ring-offset-shadow),var(--tw-ring-shadow),var(--tw-shadow,0 0 #0000);--tw-ring-color:rgba(99,102,241,.2)}
.dd-app .dd-dd-chev{height:1rem;width:1rem;flex-shrink:0;--tw-text-opacity:1;color:rgb(107 114 128/var(--tw-text-opacity,1));transition-property:transform;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.2s}
.dd-app .dd-dd.is-open .dd-dd-chev{--tw-rotate:180deg;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y))}
.dd-app .dd-dd-panel{position:absolute;top:100%;left:0;z-index:200;margin-top:.25rem;display:none;max-height:420px;width:-moz-max-content;width:max-content;min-width:100%;overflow-y:auto;border-radius:.5rem;border-width:1px;--tw-border-opacity:1;border-color:rgb(209 213 219/var(--tw-border-opacity,1));--tw-bg-opacity:1;background-color:rgb(255 255 255/var(--tw-bg-opacity,1));padding-top:.25rem;padding-bottom:.25rem;--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow)}
.dd-app .dd-dd.is-open .dd-dd-panel{display:block}
.dd-app .dd-dd.is-disabled{pointer-events:none;opacity:.6}
.dd-app .dd-dd-group{-webkit-user-select:none;-moz-user-select:none;user-select:none;padding:.5rem 1rem .25rem;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.025em;--tw-text-opacity:1;color:rgb(156 163 175/var(--tw-text-opacity,1))}
.dd-app .dd-dd-opt{cursor:pointer;white-space:nowrap;padding:.5rem 1rem;font-size:13px;--tw-text-opacity:1;color:rgb(55 65 81/var(--tw-text-opacity,1));transition-property:color,background-color,border-color,text-decoration-color,fill,stroke;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}
.dd-app .dd-dd-opt.is-selected,.dd-app .dd-dd-opt:hover{--tw-bg-opacity:1;background-color:rgb(238 242 255/var(--tw-bg-opacity,1))}
.dd-app .dd-dd-opt.is-selected{font-weight:500;--tw-text-opacity:1;color:rgb(67 56 202/var(--tw-text-opacity,1))}
.dd-app .dd-tip{position:relative;display:inline-flex;align-items:center;vertical-align:middle}
.dd-app .dd-tip .dashicons{cursor:help;--tw-text-opacity:1;color:rgb(129 140 248/var(--tw-text-opacity,1));font-size:15px;width:15px;height:15px;line-height:15px}
.dd-app .dd-tip:hover .dashicons{--tw-text-opacity:1;color:rgb(79 70 229/var(--tw-text-opacity,1))}
.dd-app .dd-tip-box{pointer-events:none;visibility:hidden;position:absolute;bottom:100%;left:50%;z-index:9999;margin-bottom:.5rem;width:-moz-max-content;width:max-content;max-width:240px;--tw-translate-x:-50%;transform:translate(var(--tw-translate-x),var(--tw-translate-y)) rotate(var(--tw-rotate)) skewX(var(--tw-skew-x)) skewY(var(--tw-skew-y)) scaleX(var(--tw-scale-x)) scaleY(var(--tw-scale-y));border-radius:.5rem;border-width:1px;--tw-border-opacity:1;border-color:rgb(224 231 255/var(--tw-border-opacity,1));--tw-bg-opacity:1;background-color:rgb(238 242 255/var(--tw-bg-opacity,1));padding:.625rem;text-align:center;font-size:.75rem;line-height:1rem;font-weight:400;font-style:normal;line-height:1.625;--tw-text-opacity:1;color:rgb(55 48 163/var(--tw-text-opacity,1));opacity:0;--tw-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);--tw-shadow-colored:0 20px 25px -5px var(--tw-shadow-color),0 8px 10px -6px var(--tw-shadow-color);box-shadow:var(--tw-ring-offset-shadow,0 0 #0000),var(--tw-ring-shadow,0 0 #0000),var(--tw-shadow);transition-property:opacity;transition-timing-function:cubic-bezier(.4,0,.2,1);transition-duration:.15s}
.dd-app .dd-tip:hover .dd-tip-box{visibility:visible;opacity:1}

/* Header bug-report button — EXACT twin of the DevDome dashboard .stats-btn bug button. */
.dd-app .mc-bug-btn { width:36px; height:36px; border-radius:50px; border:1px solid #dadce0; background:#fff; display:grid; place-items:center; cursor:pointer; color:#5f6368; transition:all .2s; text-decoration:none; }
.dd-app .mc-bug-btn:hover { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
.dd-app .mc-bug-btn svg { width:16px; height:16px; }
.dd-app .mc-bug-btn:focus { outline:none; box-shadow:none; }
.dd-app .dd-btn .dashicons { margin-right:5px; }
.dd-app .lm-scan-main { min-width:170px; justify-content:center; }
/* The tool-action rows never wrap — every button stays on one line. */
.dd-app .lm-actions { flex-wrap:nowrap; margin-bottom:0; }
.dd-app .lm-actions .dd-btn { white-space:nowrap; flex-shrink:0; }
.dd-app .lm-progress { height:8px; background:#e5e7eb; border-radius:999px; overflow:hidden; }
.dd-app .lm-progress > span { display:block; height:100%; background:#4f46e5; width:0; transition:width .55s ease; }
.dd-app .lm-progress-wrap.is-done .lm-progress > span { background:#10b981; }
.dd-app .lm-progress-wrap.is-done .lm-progress-msg { color:#059669; font-weight:600; }
/* SVG ring (Bot Protection style) — disable the old CSS conic donut behind it. */
.dd-app .dd-donut { background:none; }
.dd-app .dd-donut:before { content:none; }
/* Status badges on the review tables. */
.dd-app .lm-badge { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; padding:2px 7px; border-radius:999px; white-space:nowrap; }
.dd-app .lm-badge-ok { background:#ecfdf5; color:#059669; }
.dd-app .lm-badge-broken { background:#fef2f2; color:#dc2626; }
.dd-app .lm-badge-redirect, .dd-app .lm-badge-suspect { background:#fffbeb; color:#b45309; }
.dd-app .lm-badge-timeout, .dd-app .lm-badge-pending, .dd-app .lm-badge-dismissed { background:#f3f4f6; color:#6b7280; }
.dd-app .lm-badge-blocked { background:#eef2ff; color:#4338ca; }
/* Fallback for a status the client does not know: class names are never derived from
   server strings, so an unexpected status lands here instead of injecting a class. */
.dd-app .lm-badge-unknown { background:#f3f4f6; color:#6b7280; }
/* Data tables: dd-th's fixed width breaks 6-col lists, relax it inside the apps. */
/* Data lists = DESIGN.md section 19 (.dd-list): the Analytics dashboard table, 1:1. */
.dd-app .dd-list-wrap { overflow-x:auto; border-top:1px solid #e5e7eb; }
.dd-app .dd-list { width:100%; border-collapse:separate; border-spacing:0; }
.dd-app .dd-list thead th, .dd-app .dd-list thead .dd-th { width:auto; background:#f9fafb; padding:8px 12px; text-align:left; font-size:12px; line-height:16px; font-weight:500; color:#6b7280; letter-spacing:.05em; white-space:nowrap; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
.dd-app .dd-list tbody td, .dd-app .dd-list tbody .dd-td { padding:10px 12px; font-size:13px; line-height:18px; color:#374151; vertical-align:middle; border-bottom:1px solid #e5e7eb; border-right:1px solid #e5e7eb; }
.dd-app .dd-list th:last-child, .dd-app .dd-list td:last-child { border-right:0; }
.dd-app .dd-list tbody tr:last-child td { border-bottom:0; }
/* Checkboxes must be visible: dark border, 16px. */
.dd-app .dd-list .dd-check, .dd-app .lm-col-check .dd-check { width:16px; height:16px; border:1px solid #6b7280; border-radius:4px; margin:0; vertical-align:middle; }
.dd-app .dd-list tbody tr:hover td { background:#f8fbff; }
.dd-app .lm-url { display:block; max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.dd-app .lm-ref { display:block; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#6b7280; }
.dd-app .lm-cell-actions .dd-btn { margin:0 4px 4px 0; }
/* Review Links table: capped URL/anchor/source widths + a 2x2 action grid keep rows short. */
.dd-app #lm-links-app .lm-url { max-width:200px; }
.dd-app #lm-links-app .lm-anchor { max-width:100px; }
.dd-app .lm-status-detail { display:block; margin-top:4px; max-width:150px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.dd-app #lm-fof-app .lm-cell-actions { white-space:nowrap; }
.dd-app #lm-fof-app .lm-cell-actions .dd-btn { margin:0 4px 0 0; }
.dd-app #lm-fof-app tbody td:nth-child(4) { white-space:nowrap; }
.dd-app .lm-src { display:block; max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.dd-app .lm-act-grid { display:grid; grid-template-columns:auto auto; gap:4px; justify-content:start; white-space:nowrap; }
.dd-app .lm-act-grid .dd-btn { margin:0; justify-content:center; }
/* Filter pills = Safe Media Cleaner .mc-pill-btn twin (DESIGN.md section 19). */
.dd-app .lm-pills { display:inline-flex; gap:6px; flex-wrap:wrap; align-items:center; }
.dd-app .lm-pill { display:inline-flex; align-items:center; gap:7px; border:1px solid #e5e7eb; background:#fff; padding:5px 12px; border-radius:999px; font-size:12.5px; font-weight:600; color:#6b7280; cursor:pointer; white-space:nowrap; font-family:inherit; line-height:1.4; }
.dd-app .lm-pill:hover { border-color:#c7d2fe; color:#4338ca; }
.dd-app .lm-pill.is-on { background:#eef2ff; border-color:#c7d2fe; color:#4338ca; }
.dd-app .lm-pill:focus { outline:none; box-shadow:none; }
.dd-app .lm-pill-num { font-weight:700; color:#9ca3af; }
.dd-app .lm-pill.is-on .lm-pill-num { color:#4f46e5; }
/* Columns menu (Analytics dashboard table-filters dropdown twin). */
.dd-app .lm-cols { position:relative; }
.dd-app .lm-cols-panel { position:absolute; right:0; top:calc(100% + 6px); z-index:50; min-width:180px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1); padding:6px 0; }
.dd-app .lm-cols-row { display:flex; align-items:center; gap:10px; padding:7px 14px; font-size:13px; color:#374151; cursor:pointer; margin:0; }
.dd-app .lm-cols-row:hover { background:#f3f4f6; }
/* Bulk actions row (Analytics "Delete selected (n)" twin). */
.dd-app .lm-selcount { font-size:13px; font-weight:600; color:#374151; white-space:nowrap; }
/* Sortable headers = Analytics dashboard .th-sort, 1:1. */
.dd-app .dd-list th.th-sort { cursor:pointer; user-select:none; }
.dd-app .dd-list th.th-sort:hover { background:#f3f4f6; }
.dd-app .dd-list th.th-sort:focus { outline:none; box-shadow:none; }
.dd-app .th-sort-ind { display:inline-block; margin-left:3px; font-size:9px; opacity:.3; vertical-align:1px; }
.dd-app .th-sort-ind::after { content:"\25BC"; }
.dd-app .th-sort.sorted-desc .th-sort-ind, .dd-app .th-sort.sorted-asc .th-sort-ind { opacity:1; color:#1967d2; }
.dd-app .th-sort.sorted-asc .th-sort-ind::after { content:"\25B2"; }
.dd-app .lm-num-h { color:#1967d2; font-weight:700; font-size:14px; }
.dd-app .lm-num-b { color:#be123c; font-weight:700; font-size:14px; }
.dd-app #lm-fof-app th[data-col="humans"], .dd-app #lm-fof-app th[data-col="bots"] { width:84px; text-align:center; }
.dd-app #lm-fof-app td[data-col="humans"], .dd-app #lm-fof-app td[data-col="bots"] { text-align:center; }
.dd-app #lm-select-lm_fof_action { width:240px; }
.dd-app #lm-fof-apply:disabled { opacity:.5; cursor:default; }
.dd-app .lm-copy-btn svg, .dd-app .lm-copy-btn .lm-svg { width:15px; height:15px; display:block; }
.dd-app #lm-select-lm_bulk_action { width:240px; }
.dd-app #lm-apply:disabled { opacity:.5; cursor:default; }
/* Lists show the FULL text (owner rule 2026-08-24): wrap long URLs, never ellipsis. */
.dd-app #lm-links-app .lm-url, .dd-app #lm-fof-app .lm-url, .dd-app .lm-ref, .dd-app .lm-src, .dd-app #lm-links-app .lm-anchor { max-width:none; white-space:normal; overflow:visible; text-overflow:clip; word-break:break-all; }
.dd-app #lm-links-app th[data-col="url"] { min-width:240px; width:auto; }
.dd-app #lm-links-app th[data-col="status"] { width:84px; }
.dd-app #lm-links-app td[data-col="status"] .lm-badge { white-space:normal; line-height:1.3; }
.dd-app #lm-links-app td[data-col="status"] .lm-status-detail { white-space:normal; }
.dd-app #lm-links-app td[data-col="status"] { max-width:84px; }
.dd-app .lm-code { display:inline-block; margin-top:5px; font-size:10px; font-weight:700; padding:1px 7px; border-radius:999px; white-space:nowrap; }
.dd-app .lm-code-bad { background:#fef2f2; color:#dc2626; }
.dd-app .lm-code-warn { background:#fffbeb; color:#b45309; }
.dd-app .lm-code-ok { background:#ecfdf5; color:#059669; }
.dd-app .lm-code-muted { background:#f3f4f6; color:#6b7280; }
.dd-app .lm-url .lm-code { margin:0 0 0 8px; vertical-align:middle; }
.dd-app .lm-col-check { width:36px; }
/* Titles and anchors wrap at word boundaries; only URLs may break anywhere. */
.dd-app .lm-src, .dd-app #lm-links-app .lm-anchor { word-break:normal; overflow-wrap:anywhere; }
.dd-app .lm-col-hidden { display:none; }
/* A refresh in flight: rows stay on screen, slightly dimmed, never replaced by "Loading...". */
.dd-app .is-loading .dd-list tbody { opacity:.55; transition:opacity .15s; }
/* Dialog = DESIGN.md section 20 (.dd-modal). Above the admin bar (99999). */
.dd-app .dd-modal { position:fixed; inset:0; z-index:100000; display:flex; align-items:center; justify-content:center; }
.dd-app .dd-modal[hidden] { display:none; }
.dd-app .dd-modal-backdrop { position:absolute; inset:0; background:rgba(15,23,42,.45); }
.dd-app .dd-modal-box { position:relative; width:min(560px, calc(100vw - 32px)); background:#fff; border:1px solid #e5e7eb; border-radius:12px; box-shadow:0 20px 25px -5px rgba(0,0,0,.2), 0 8px 10px -6px rgba(0,0,0,.1); }
.dd-app .dd-modal-head { display:flex; align-items:center; gap:10px; padding:16px 20px; border-bottom:1px solid #f3f4f6; }
.dd-app .dd-modal-head h3 { margin:0; font-size:16px; font-weight:700; color:#1f2937; }
.dd-app .dd-modal-x { margin-left:auto; border:0; background:transparent; font-size:22px; line-height:1; color:#9ca3af; cursor:pointer; padding:0 2px; }
.dd-app .dd-modal-x:hover { color:#374151; }
.dd-app .dd-modal-x:focus { outline:none; box-shadow:none; }
.dd-app .dd-modal-body { padding:20px; }
.dd-app .dd-modal-label { display:block; font-size:12px; font-weight:600; color:#4b5563; margin:0 0 6px; }
.dd-app .dd-modal-current { font-size:13px; color:#374151; word-break:break-all; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:8px 10px; margin:0 0 14px; }
.dd-app .dd-modal-error { margin-top:10px; background:#fef2f2; color:#b91c1c; border:1px solid #fecaca; border-radius:8px; padding:8px 10px; font-size:13px; }
.dd-app .dd-modal-error[hidden] { display:none; }
.dd-app .dd-modal-foot { display:flex; justify-content:flex-end; gap:8px; padding:14px 20px; border-top:1px solid #f3f4f6; }
.dd-app .lm-notice { margin:0 0 12px; }
/* Dialog "Found in" list: one row per post/page/product that holds the link. */
.dd-app .dd-modal-list { list-style:none; margin:0; padding:0; border:1px solid #e5e7eb; border-radius:8px; max-height:190px; overflow:auto; }
.dd-app .dd-modal-list li { display:flex; align-items:center; gap:10px; padding:7px 10px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#374151; }
.dd-app .dd-modal-list li:last-child { border-bottom:0; }
.dd-app .dd-modal-list .lm-src-type { flex:none; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#4338ca; background:#eef2ff; border-radius:999px; padding:1px 7px; }
.dd-app .dd-modal-list .lm-src-title { flex:1 1 auto; min-width:0; overflow-wrap:anywhere; }
.dd-app .dd-modal-list .lm-src-view { flex:none; font-size:12px; color:#6b7280; text-decoration:none; }
.dd-app .dd-modal-list .lm-src-view:hover { color:#4f46e5; }
.dd-app .dd-modal-list li.is-empty { color:#9ca3af; }
/* Pagination bar (Analytics dashboard #pagination-bar twin): count left, per-page + pages right. */
.dd-app .lm-pagination { display:flex; flex-wrap:nowrap; align-items:center; justify-content:space-between; gap:8px; padding:10px 12px; border-top:1px solid #e5e7eb; }
.dd-app .lm-pagination:empty { display:none; }
.dd-app .lm-pg-count { white-space:nowrap; font-size:12.5px; color:#3c4043; }
.dd-app .lm-pg-right { display:flex; flex-wrap:nowrap; align-items:center; gap:12px; flex:none; }
.dd-app .lm-pg-per { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; color:#3c4043; }
.dd-app .lm-pg-btns { display:flex; align-items:center; gap:4px; }
.dd-app .page-btn { min-width:30px; height:30px; padding:0 8px; border-radius:6px; border:1px solid #dadce0; background:#fff; color:#3c4043; font-family:inherit; font-size:13px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; transition:background .12s, border-color .12s, color .12s; }
.dd-app .page-btn:hover:not(:disabled) { background:#f8fbff; border-color:#1967d2; color:#1967d2; }
.dd-app .page-btn.active { background:#1967d2; border-color:#1967d2; color:#fff; }
.dd-app .page-btn:disabled { color:#bdc1c6; cursor:not-allowed; }
.dd-app .page-btn:focus { outline:none; box-shadow:none; }
.dd-app .page-gap { color:#94a3b8; padding:0 4px; font-size:13px; user-select:none; }
.dd-app .page-jump-wrap { position:relative; display:block; }
.dd-app .page-jump-menu { position:absolute; bottom:calc(100% + 6px); right:0; width:78px; max-height:336px; overflow-y:auto; overscroll-behavior:contain; background:#fff; border:1px solid #dadce0; border-radius:10px; box-shadow:0 12px 32px rgba(15,23,42,.14), 0 2px 8px rgba(15,23,42,.06); padding:4px; z-index:40; display:flex; flex-direction:column; }
.dd-app .page-jump-menu.hidden { display:none; }
.dd-app .pjm-item { border:0; background:none; font-family:inherit; font-size:13px; font-weight:500; padding:8px 10px; border-radius:6px; cursor:pointer; color:#3c4043; text-align:center; flex:none; }
.dd-app .pjm-item:hover { background:#f8fafc; }
.dd-app .pjm-item.active { background:#e8f0fe; color:#1967d2; font-weight:700; }
/* URL + pencil: edit sits right after the link text. */
.dd-app .dd-link-btn{background:none;border:0;padding:0;margin:0 8px 0 0;color:#2563eb;font:inherit;font-size:12.5px;cursor:pointer;text-decoration:none}.dd-link-btn:hover{text-decoration:underline}.dd-link-btn:disabled{color:#6b7280;cursor:default;text-decoration:none}.lm-suggest{display:inline-flex;align-items:center;gap:6px;flex-wrap:wrap}
.lm-url-row { display:flex; align-items:flex-start; gap:4px; }
.dd-app .lm-url-row .lm-url { flex:0 1 auto; min-width:0; }
.dd-app .lm-edit-btn { flex:none; border:0; background:transparent; padding:0 2px; margin:0; color:#9ca3af; cursor:pointer; line-height:1; }
.dd-app .lm-edit-btn:hover { color:#4f46e5; }
.dd-app .lm-edit-btn:focus { outline:none; box-shadow:none; color:#4f46e5; }
.dd-app .lm-edit-btn .dashicons { font-size:16px; width:16px; height:16px; }
/* Stacked secondary lines under the link ("Page: …", "Anchor: …"). */
.dd-app .lm-meta { display:block; margin-top:5px; font-size:13px; line-height:18px; color:#374151; }
.dd-app .lm-meta > b { font-weight:600; color:#111827; margin-right:4px; }
.dd-app .lm-meta .lm-ref, .dd-app .lm-meta .lm-url { display:inline; }
/* Pages = chips: the full title is visible and clearly clickable; 10 shown, the rest behind "Show more". */
.dd-app .lm-page { display:inline-block; margin:2px 6px 2px 0; padding:1px 8px; border-radius:6px; background:#eef2ff; color:#4338ca; text-decoration:none; font-size:12.5px; line-height:18px; overflow-wrap:anywhere; }
.dd-app .lm-page:hover { background:#e0e7ff; color:#3730a3; text-decoration:underline; }
.dd-app .lm-page[hidden] { display:none; }
.dd-app .lm-more { display:inline-block; margin:2px 0; border:0; background:transparent; color:#4f46e5; cursor:pointer; font-size:12.5px; font-weight:600; padding:0; font-family:inherit; }
.dd-app .lm-more:hover { text-decoration:underline; }
.dd-app .lm-more:focus { outline:none; box-shadow:none; }
/* Copy button next to the pencil. */
.dd-app .lm-copy-btn { flex:none; border:0; background:transparent; padding:0 2px; margin:0; color:#9ca3af; cursor:pointer; line-height:1; }
.dd-app .lm-copy-btn:hover, .dd-app .lm-copy-btn.is-done { color:#4f46e5; }
.dd-app .lm-copy-btn:focus { outline:none; box-shadow:none; }
.dd-app .lm-copy-btn .dashicons { font-size:16px; width:16px; height:16px; }
.dd-app .lm-status-detail { white-space:nowrap; }
/* Section-13 header icon buttons. */
.dd-app .dd-io-btn { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:8px; color:#6b7280; background:transparent; border:1px solid transparent; text-decoration:none; cursor:pointer; }
.dd-app .dd-io-btn:hover { background:#f3f4f6; color:#374151; }
.dd-app .dd-io-btn .dashicons { font-size:18px; width:18px; height:18px; }
.dd-app .dd-tabpanel { display:none; }
.dd-app .dd-tabpanel.is-active { display:block; }
CSS;
}

/** Instant client-side tab switcher (attached to the admin script via wp_add_inline_script). */
function devdlink_inline_js()
{
    return <<<'JS'
(function () {
    var app = document.querySelector('.dd-app');
    if (!app) { return; }
    var dds = Array.prototype.slice.call(app.querySelectorAll('.dd-dd'));
    function ddCloseAll(except) { dds.forEach(function (d) { if (d !== except) { d.classList.remove('is-open'); } }); }
    function ddApplyValue(dd, value, fire) {
        var input = dd.querySelector('input[type="hidden"]'), label = dd.querySelector('.dd-dd-label'), chosen = null;
        Array.prototype.forEach.call(dd.querySelectorAll('.dd-dd-opt'), function (o) {
            var sel = (o.getAttribute('data-value') === value);
            o.classList.toggle('is-selected', sel);
            if (sel) { chosen = o; }
        });
        if (input) { input.value = value; }
        if (label && chosen) { label.textContent = chosen.textContent; }
        if (fire && input) { input.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    dds.forEach(function (dd) {
        var trigger = dd.querySelector('.dd-dd-trigger');
        if (!trigger) { return; }
        function ddSetOpen(open) { ddCloseAll(dd); dd.classList.toggle('is-open', open); trigger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
        function ddOpts() { return Array.prototype.slice.call(dd.querySelectorAll('.dd-dd-opt')); }
        function ddFocusOpt(i) { var o = ddOpts(); if (!o.length) { return; } i = Math.max(0, Math.min(o.length - 1, i)); o[i].focus(); }
        function ddChoose(opt) { ddApplyValue(dd, opt.getAttribute('data-value'), true); ddOpts().forEach(function (o) { o.setAttribute('aria-selected', o === opt ? 'true' : 'false'); }); ddSetOpen(false); trigger.focus(); }
        trigger.addEventListener('click', function (e) { e.stopPropagation(); ddSetOpen(!dd.classList.contains('is-open')); });
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault(); ddSetOpen(true);
                var o = ddOpts(), sel = -1;
                o.forEach(function (x, i) { if (sel < 0 && x.classList.contains('is-selected')) { sel = i; } });
                ddFocusOpt(sel >= 0 ? sel : 0);
            } else if (e.key === 'Escape') { ddSetOpen(false); }
        });
        ddOpts().forEach(function (opt, idx) {
            opt.addEventListener('click', function (e) { e.stopPropagation(); ddChoose(opt); });
            opt.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); ddFocusOpt(idx + 1); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); ddFocusOpt(idx - 1); }
                else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); ddChoose(opt); }
                else if (e.key === 'Escape' || e.key === 'Tab') { ddSetOpen(false); if (e.key === 'Escape') { e.preventDefault(); trigger.focus(); } }
            });
        });
    });
    document.addEventListener('click', function () { ddCloseAll(null); dds.forEach(function (d) { var t = d.querySelector('.dd-dd-trigger'); if (t) { t.setAttribute('aria-expanded', 'false'); } }); });
    window.ddSetSelect = function (name, value) { var dd = app.querySelector('.dd-dd[data-name="' + name + '"]'); if (dd) { ddApplyValue(dd, value, true); } };
    var tabs = Array.prototype.slice.call(app.querySelectorAll('.dd-tab[data-dd-tab]'));
    var panels = Array.prototype.slice.call(app.querySelectorAll('.dd-tabpanel[data-dd-panel]'));
    function devdlink_show_tab(name, focusTab) {
        if (name === 'dashboard') { name = 'overview'; } // pre-1.5.2 hash of this tab
        var found = false;
        panels.forEach(function (p) {
            var on = p.getAttribute('data-dd-panel') === name;
            p.classList.toggle('is-active', on);
            // hidden, not just display:none — keyboard and screen readers must not reach
            // an inactive panel.
            if (on) { p.removeAttribute('hidden'); found = true; } else { p.setAttribute('hidden', 'hidden'); }
        });
        if (!found) { return; }
        var stale = document.querySelectorAll('.lm-saved-banner');
        if (stale.length) {
            stale.forEach(function (b) { b.parentNode.removeChild(b); });
            if (window.history && history.replaceState && /[?&]lm_saved=/.test(location.search)) {
                var clean = location.search.replace(/([?&])lm_saved=[^&]*&?/, '$1').replace(/[?&]$/, '');
                history.replaceState(null, '', location.pathname + clean + location.hash);
            }
        }
        document.querySelectorAll('[data-lm-tab-only]').forEach(function (el) {
            el.style.display = (el.getAttribute('data-lm-tab-only') === name) ? '' : 'none';
        });
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-dd-tab') === name;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.setAttribute('tabindex', on ? '0' : '-1');
            if (on && focusTab) { t.focus(); }
        });
    }
    tabs.forEach(function (t, i) {
        t.addEventListener('click', function (e) {
            e.preventDefault();
            var name = t.getAttribute('data-dd-tab');
            devdlink_show_tab(name);
            if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
        });
        // Arrow/Home/End move between tabs; Enter/Space activate (a link already does).
        t.addEventListener('keydown', function (e) {
            var target = -1;
            if (e.key === 'ArrowRight' || e.key === 'ArrowDown') { target = (i + 1) % tabs.length; }
            else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') { target = (i - 1 + tabs.length) % tabs.length; }
            else if (e.key === 'Home') { target = 0; }
            else if (e.key === 'End') { target = tabs.length - 1; }
            else if (e.key === ' ' || e.key === 'Spacebar') { target = i; }
            else { return; }
            e.preventDefault();
            var name = tabs[target].getAttribute('data-dd-tab');
            devdlink_show_tab(name, true);
            if (window.history && history.replaceState) { history.replaceState(null, '', '#' + name); }
        });
    });
    // In-panel cross-links (href="#links", "#fof") switch instantly too.
    window.addEventListener('hashchange', function () {
        var h = (location.hash || '').replace('#', '');
        if (h) { devdlink_show_tab(h); }
    });
    var hash = (location.hash || '').replace('#', '');
    if (hash) { devdlink_show_tab(hash); }
})();
JS;
}

/* ----------------------------- save handlers ---------------------------- */

/** Settings tab save (self-POST, nonce + cap, PRG). */
function devdlink_handle_settings_save()
{
    if (empty($_POST['devdlink_settings_save']) || !current_user_can(devdlink_capability('configure'))) {
        return;
    }
    check_admin_referer('devdlink_settings', '_lmsn');

    $timeout = isset($_POST['lm_check_timeout']) ? (int) $_POST['lm_check_timeout'] : 10;
    devdlink_update_setting('check_timeout', max(3, min(30, $timeout)));

    $chunk = isset($_POST['lm_scan_chunk_size']) ? (int) $_POST['lm_scan_chunk_size'] : 50;
    devdlink_update_setting('scan_chunk_size', max(10, min(500, $chunk)));

    $batch = isset($_POST['lm_check_batch_size']) ? (int) $_POST['lm_check_batch_size'] : 15;
    devdlink_update_setting('check_batch_size', max(3, min(50, $batch)));

    $ua = isset($_POST['lm_user_agent']) ? sanitize_text_field(wp_unslash($_POST['lm_user_agent'])) : '';
    $ua = substr($ua, 0, 255);
    if ($ua === '') {
        $ua = 'Mozilla/5.0 (compatible; DevDomeLinkMonitor/' . DEVDLINK_VERSION . '; +https://devdome.com)';
    }
    devdlink_update_setting('user_agent', $ua);

    $excluded_raw = isset($_POST['lm_excluded_domains']) ? sanitize_textarea_field(wp_unslash($_POST['lm_excluded_domains'])) : '';
    $excluded = array();
    foreach (preg_split('/\r\n|\r|\n/', $excluded_raw) as $line) {
        $line = strtolower(trim($line));
        if ($line === '') {
            continue;
        }
        // Accept bare hosts or full URLs; store the host only.
        if (strpos($line, '://') !== false || strpos($line, '//') === 0) {
            $host = wp_parse_url($line, PHP_URL_HOST);
            $line = $host ? $host : '';
        }
        $line = sanitize_text_field(trim($line, " \t/"));
        if ($line !== '') {
            $excluded[] = $line;
        }
    }
    devdlink_update_setting('excluded_domains', array_values(array_unique($excluded)));

    $purge = isset($_POST['lm_purge_days']) ? (int) $_POST['lm_purge_days'] : 90;
    devdlink_update_setting('purge_days', in_array($purge, array(30, 90, 180), true) ? $purge : 90);

    // Privacy: how much of a referrer may be stored (a query string never is) and whether the
    // user-agent string is kept at all.
    $ref_mode = isset($_POST['lm_referrer_mode']) ? sanitize_key(wp_unslash($_POST['lm_referrer_mode'])) : 'origin_path';
    devdlink_update_setting('referrer_mode', in_array($ref_mode, array('origin_path', 'origin', 'none'), true) ? $ref_mode : 'origin_path');
    devdlink_update_setting('store_user_agent', empty($_POST['lm_store_user_agent']) ? 0 : 1);

    $sched = isset($_POST['lm_scheduled_rescan']) ? sanitize_key(wp_unslash($_POST['lm_scheduled_rescan'])) : 'off';
    devdlink_update_setting('scheduled_rescan', in_array($sched, array('off', 'weekly', 'monthly'), true) ? $sched : 'off');

    // Email summaries: only a connected DevDome account can turn them on, and they go to
    // that account's email only (spam-complaint guard: no free-typed recipient anywhere).
    $conn = function_exists('devdcorev1_connection_state') ? devdcorev1_connection_state() : array('ok' => 0);
    devdlink_update_setting('email_new_broken', (!empty($conn['ok']) && !empty($_POST['lm_email_new_broken'])) ? 1 : 0);

    wp_safe_redirect(add_query_arg(array('page' => DEVDLINK_PAGE, 'lm_tab' => 'settings', 'lm_saved' => '1'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdlink_handle_settings_save');

/** Dismiss the persisted last-error banner (nonce + cap, PRG). */
function devdlink_handle_clear_error()
{
    if (empty($_GET['lm_clear_error']) || !current_user_can(devdlink_capability('view'))) {
        return;
    }
    check_admin_referer('devdlink_clear_error', '_lmerr');
    if (function_exists('devdlink_clear_last_error')) {
        devdlink_clear_last_error();
    }
    wp_safe_redirect(add_query_arg(array('page' => DEVDLINK_PAGE, 'lm_tab' => 'overview'), admin_url('admin.php')));
    exit;
}
add_action('admin_init', 'devdlink_handle_clear_error');

/* ----------------------------- helpers ---------------------------------- */

/**
 * Render a settings dropdown: the design-system listbox with full keyboard support.
 *
 * This is the design-system control (hidden input + trigger + option list), NOT a native
 * <select>: it keeps the suite's look. Since 1.4.13 it carries listbox/option roles,
 * aria-expanded/aria-selected and full keyboard operation (Enter/Space/arrows open and move,
 * Enter/Space select, Escape closes), so assistive technology gets name, role, value and state.
 *
 * @param string $name    the posted field name
 * @param mixed  $current currently selected option key
 * @param array  $options [value => label] (labels already translated; printed escaped)
 * @param string $label   accessible name for the control
 */
function devdlink_render_dropdown($name, $current, $options, $label = '')
{
    $current = (string) $current;
    $id = 'lm-select-' . sanitize_key($name);
    $current_label = isset($options[$current]) ? $options[$current] : reset($options);
    ?>
    <div class="dd-dd w-64" data-name="<?php echo esc_attr($name); ?>" id="<?php echo esc_attr($id); ?>">
        <input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($current); ?>">
        <div class="dd-dd-trigger" tabindex="0" role="button" aria-haspopup="listbox" aria-expanded="false"<?php echo $label !== '' ? ' aria-label="' . esc_attr($label) . '"' : ''; ?>>
            <span class="dd-dd-label"><?php echo esc_html($current_label); ?></span>
            <svg class="dd-dd-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"></path></svg>
        </div>
        <div class="dd-dd-panel" role="listbox"<?php echo $label !== '' ? ' aria-label="' . esc_attr($label) . '"' : ''; ?>>
            <?php foreach ($options as $val => $option_label) : ?>
                <div class="dd-dd-opt<?php echo ((string) $val === $current) ? ' is-selected' : ''; ?>" role="option" tabindex="-1" aria-selected="<?php echo ((string) $val === $current) ? 'true' : 'false'; ?>" data-value="<?php echo esc_attr($val); ?>"><?php echo esc_html($option_label); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/* ----------------------------- page shell ------------------------------- */

function devdlink_render_page()
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selection, nothing is changed.
    $tab = isset($_GET['lm_tab']) ? sanitize_key(wp_unslash($_GET['lm_tab'])) : 'overview';
    if ($tab === 'dashboard') {
        $tab = 'overview'; // Pre-1.5.2 name of this tab: old links and bookmarks still land here.
    }
    if (!in_array($tab, array('overview', 'links', 'fof', 'settings'), true)) {
        $tab = 'overview';
    }
    $s = devdlink_hub_summary();
    ?>
    <div class="dd-app min-h-screen bg-gray-50 text-[#3c434a] font-sans text-[13px]">
        <div class="bg-white border-b border-gray-200 shadow-sm">
            <div class="max-w-5xl px-6 py-4 flex items-center gap-3">
                <div class="p-1.5 rounded text-white inline-flex items-center justify-center" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18.84 12.25 1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-6.95 0l-1.72 1.71"/><path d="m5.17 11.75-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 6.95 0l1.71-1.71"/><line x1="8" x2="8" y1="2" y2="5"/><line x1="2" x2="5" y1="8" y2="8"/><line x1="16" x2="16" y1="19" y2="22"/><line x1="19" x2="22" y1="16" y2="16"/></svg></div>
                <h1 class="text-xl font-bold text-gray-800 m-0"><?php esc_html_e('DevDome Link Monitor', 'devdome-link-monitor'); ?></h1>
                <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
                    <a class="mc-bug-btn" href="<?php echo esc_url('https://devdome.com/report-bug?plugin=devdome-link-monitor&v=' . DEVDLINK_VERSION); ?>" target="_blank" rel="noopener noreferrer" title="<?php esc_attr_e('Report a bug', 'devdome-link-monitor'); ?>">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 2 1.88 1.88"/><path d="M14.12 3.88 16 2"/><path d="M9 7.13v-1a3.003 3.003 0 1 1 6 0v1"/><path d="M12 20c-3.3 0-6-2.7-6-6v-3a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v3c0 3.3-2.7 6-6 6"/><path d="M12 20v-9"/><path d="M6.53 9C4.6 8.8 3 7.1 3 5"/><path d="M6 13H2"/><path d="M3 21c0-2.1 1.7-3.9 3.8-4"/><path d="M20.97 5c0 2.1-1.6 3.8-3.5 4"/><path d="M22 13h-4"/><path d="M17.2 17c2.1.1 3.8 1.9 3.8 4"/></svg>
                    </a>
                    <?php // Section-13 header icons: export the list of the ACTIVE tab (shown by the tab switcher). ?>
                    <a class="dd-io-btn" data-lm-tab-only="links" href="<?php echo esc_url(devdlink_links_export_url()); ?>" title="<?php esc_attr_e('Export this list as CSV', 'devdome-link-monitor'); ?>" <?php echo $tab === 'links' ? '' : 'style="display:none;"'; ?>><span class="dashicons dashicons-download"></span></a>
                    <a class="dd-io-btn" data-lm-tab-only="fof" href="<?php echo esc_url(devdlink_fof_export_url()); ?>" title="<?php esc_attr_e('Export this list as CSV', 'devdome-link-monitor'); ?>" <?php echo $tab === 'fof' ? '' : 'style="display:none;"'; ?>><span class="dashicons dashicons-download"></span></a>
                </div>
            </div>
            <?php
            // WAI-ARIA tab pattern: every tab owns its panel by id, carries its selected state,
            // and only the selected tab is in the tab order (arrow keys move between them).
            $tabs = array(
                'overview'  => array('dashicons-dashboard', __('Overview', 'devdome-link-monitor')),
                'links'     => array('dashicons-editor-unlink', __('Broken Links', 'devdome-link-monitor')),
                'fof'       => array('dashicons-warning', __('404 Monitor', 'devdome-link-monitor')),
                'settings'  => array('dashicons-admin-generic', __('Settings', 'devdome-link-monitor')),
            );
            ?>
            <div class="dd-tabs max-w-5xl" style="margin:0;border-bottom:0;" role="tablist" aria-label="<?php esc_attr_e('Link Monitor sections', 'devdome-link-monitor'); ?>">
                <?php foreach ($tabs as $key => $meta) : $on = ($tab === $key); ?>
                    <a class="dd-tab <?php echo $on ? 'is-active' : ''; ?>"
                       href="#<?php echo esc_attr($key); ?>"
                       id="lm-tab-<?php echo esc_attr($key); ?>"
                       role="tab"
                       aria-controls="lm-panel-<?php echo esc_attr($key); ?>"
                       aria-selected="<?php echo $on ? 'true' : 'false'; ?>"
                       tabindex="<?php echo $on ? '0' : '-1'; ?>"
                       data-dd-tab="<?php echo esc_attr($key); ?>"><span class="dashicons <?php echo esc_attr($meta[0]); ?>" aria-hidden="true"></span> <?php echo esc_html($meta[1]); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <main>
            <?php // All panels render once; switching is instant client-side (no page reload). The
            // $tab from ?lm_tab= still picks the initial active panel so form-save PRG redirects land right.
            // Inactive panels carry the hidden attribute so they are out of reach of the keyboard
            // and of assistive technology, not merely display:none. ?>
            <div class="dd-tabpanel<?php echo $tab === 'overview' ? ' is-active' : ''; ?>" id="lm-panel-overview" data-dd-panel="overview" role="tabpanel" aria-labelledby="lm-tab-overview" tabindex="0" <?php echo $tab === 'overview' ? '' : 'hidden'; ?>><?php devdlink_render_dashboard_tab($s); ?></div>
            <div class="dd-tabpanel<?php echo $tab === 'links' ? ' is-active' : ''; ?>" id="lm-panel-links" data-dd-panel="links" role="tabpanel" aria-labelledby="lm-tab-links" tabindex="0" <?php echo $tab === 'links' ? '' : 'hidden'; ?>><?php devdlink_render_links_tab($s); ?></div>
            <div class="dd-tabpanel<?php echo $tab === 'fof' ? ' is-active' : ''; ?>" id="lm-panel-fof" data-dd-panel="fof" role="tabpanel" aria-labelledby="lm-tab-fof" tabindex="0" <?php echo $tab === 'fof' ? '' : 'hidden'; ?>>
                <?php
                if (function_exists('devdlink_render_404_tab')) {
                    devdlink_render_404_tab($s);
                }
                ?>
            </div>
            <div class="dd-tabpanel<?php echo $tab === 'settings' ? ' is-active' : ''; ?>" id="lm-panel-settings" data-dd-panel="settings" role="tabpanel" aria-labelledby="lm-tab-settings" tabindex="0" <?php echo $tab === 'settings' ? '' : 'hidden'; ?>><?php devdlink_render_settings_tab(); ?></div>
        </main>
    </div>
    <?php
}

/* ----------------------------- dashboard tab ---------------------------- */

function devdlink_render_dashboard_tab($s)
{
    // Exact percentage (one decimal, trailing .0 trimmed) — never a stepped approximation.
    $score = (float) $s['health_score'];
    $score_fmt = rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.');
    $never_scanned = (devdlink_get_int('last_scan_id', 0) === 0);
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php
        if (devdlink_get_int('rescan_needed', 0) === 1) : ?>
            <div class="dd-banner dd-banner-warn" style="margin-bottom:16px;">
                <div style="display:flex;align-items:flex-start;gap:10px;">
                    <span class="dashicons dashicons-info" style="margin-top:2px;"></span>
                    <div style="flex:1;">
                        <strong><?php esc_html_e('One full scan needed', 'devdome-link-monitor'); ?></strong>
                        <div style="margin-top:4px;"><?php esc_html_e('This update records link and image uses of the same URL separately. Run Scan Links once so the existing records are rebuilt with that detail.', 'devdome-link-monitor'); ?></div>
                    </div>
                </div>
            </div>
        <?php endif;
        $last_error = function_exists('devdlink_last_error') ? devdlink_last_error() : null;
        if ($last_error) :
            $err_dismiss = wp_nonce_url(add_query_arg(array('page' => DEVDLINK_PAGE, 'lm_clear_error' => '1'), admin_url('admin.php')), 'devdlink_clear_error', '_lmerr');
        ?>
            <div class="dd-banner dd-banner-error" style="margin-bottom:16px;">
                <div style="display:flex;align-items:flex-start;gap:10px;">
                    <span class="dashicons dashicons-warning" style="margin-top:2px;"></span>
                    <div style="flex:1;">
                        <strong><?php esc_html_e('Last scan reported a problem', 'devdome-link-monitor'); ?></strong>
                        <div style="margin-top:4px;"><?php echo esc_html(isset($last_error['message']) ? $last_error['message'] : ''); ?></div>
                        <?php if (!empty($last_error['context'])) : ?>
                            <details style="margin-top:6px;">
                                <summary style="cursor:pointer;"><?php esc_html_e('View details', 'devdome-link-monitor'); ?></summary>
                                <pre style="white-space:pre-wrap;font-size:11px;margin:6px 0 0;"><?php echo esc_html(wp_json_encode($last_error['context'])); ?></pre>
                            </details>
                        <?php endif; ?>
                        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="button" class="dd-btn dd-btn-sm dd-btn-primary lm-job-btn" id="lm-retry-btn" data-mode="full"><?php esc_html_e('Retry scan', 'devdome-link-monitor'); ?></button>
                            <a class="dd-btn dd-btn-sm" href="<?php echo esc_url($err_dismiss); ?>"><?php esc_html_e('Dismiss', 'devdome-link-monitor'); ?></a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php
        // Overview data. Trend = the last 12 completed full scans (one tiny indexed query on the
        // plugin's own page); everything else is the cached summary + settings.
        global $wpdb;
        $trend = $wpdb->get_results($wpdb->prepare(
            "SELECT finished_at, total_links, ok_count, broken_count FROM {$wpdb->prefix}devdlink_scans WHERE status = %s AND scan_type = %s ORDER BY id DESC LIMIT 12",
            'completed',
            'full'
        ), ARRAY_A);
        $trend = array_reverse(is_array($trend) ? $trend : array());
        $conn = function_exists('devdcorev1_connection_state') ? devdcorev1_connection_state() : array('ok' => 0, 'account_id' => '', 'email' => '');
        $connect_url = devdlink_connect_url('overview');
        $last_at = (int) $s['last_scan_at'];
        $cadence = (string) devdlink_get_setting('scheduled_rescan', 'off');
        $cadence_labels = array(
            'off'     => __('Off', 'devdome-link-monitor'),
            'weekly'  => __('Weekly', 'devdome-link-monitor'),
            'monthly' => __('Monthly', 'devdome-link-monitor'),
        );
        $email_on = (bool) devdlink_get_int('email_new_broken', 0) && !empty($conn['ok']) && !empty($conn['email']);
        $email_to = !empty($conn['email']) ? (string) $conn['email'] : '';
        $circ = 2 * M_PI * 70;
        $dash = max(0, min(100, $score)) / 100 * $circ;
        ?>

        <!-- ============ Overview: ring + Scan on the left, one compact card per tool on the right ============ -->
        <div class="lm-ov">
            <div class="dd-card lm-ov-side">
                <div class="dd-donut">
                    <svg width="160" height="160" viewBox="0 0 160 160">
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#e5e7eb" stroke-width="12"></circle>
                        <circle cx="80" cy="80" r="70" fill="none" stroke="#10b981" stroke-width="12" stroke-linecap="round"
                            stroke-dasharray="<?php echo esc_attr(round($dash, 1) . ' ' . round($circ, 1)); ?>"
                            transform="rotate(-90 80 80)"></circle>
                    </svg>
                    <span class="dd-donut-num"><?php echo esc_html($score_fmt); ?></span>
                    <span class="dd-donut-lbl"><?php esc_html_e('Link health', 'devdome-link-monitor'); ?></span>
                </div>
                <button type="button" class="dd-btn dd-btn-primary lm-scan-main lm-job-btn" id="lm-scan-btn" data-mode="full" style="width:100%;"><span class="dashicons dashicons-search"></span> <?php esc_html_e('Scan Links', 'devdome-link-monitor'); ?></button>
                <span class="dd-hint" style="margin:0;text-align:center;"><?php
                    if ($last_at > 0) {
                        /* translators: %s is a human-readable time difference, e.g. "3 hours". */
                        echo esc_html(sprintf(__('Last scan %s ago', 'devdome-link-monitor'), human_time_diff($last_at, time())));
                    } else {
                        esc_html_e('Not scanned yet', 'devdome-link-monitor');
                    }
                ?></span>
            </div>
            <div class="lm-ov-cards">
                <div class="dd-card">
                    <div class="dd-sec-head"><span class="dashicons dashicons-editor-unlink dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Broken Links', 'devdome-link-monitor'); ?></h2><a class="lm-ov-link" href="#links"><?php esc_html_e('Review links', 'devdome-link-monitor'); ?> &rarr;</a></div>
                    <?php if ($never_scanned) : ?>
                        <div class="dd-banner dd-banner-watch"><?php esc_html_e('Your content has not been scanned yet. Run a link scan to see which links are broken.', 'devdome-link-monitor'); ?></div>
                    <?php else : ?>
                        <div class="dd-stats lm-ov-stats">
                            <?php // Stat colors = Safe Media Cleaner palette: indigo total, green good, red bad, amber needs-attention. ?>
                            <div class="dd-stat"><div class="dd-stat-num" style="color:#4f46e5;"><?php echo (int) $s['links_total']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Links checked', 'devdome-link-monitor'); ?></div></div>
                            <div class="dd-stat"><div class="dd-stat-num" style="color:#10b981;"><?php echo (int) $s['links_ok']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Healthy', 'devdome-link-monitor'); ?></div></div>
                            <div class="dd-stat"><div class="dd-stat-num" style="color:#ef4444;"><?php echo (int) $s['links_broken']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Broken', 'devdome-link-monitor'); ?></div></div>
                            <div class="dd-stat"><div class="dd-stat-num" style="color:#f59e0b;"><?php echo (int) $s['links_redirect']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Redirects', 'devdome-link-monitor'); ?></div></div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="dd-card">
                    <div class="dd-sec-head"><span class="dashicons dashicons-warning dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('404 Monitor', 'devdome-link-monitor'); ?></h2><a class="lm-ov-link" href="#fof"><?php esc_html_e('Open 404 Monitor', 'devdome-link-monitor'); ?> &rarr;</a></div>
                    <?php devdlink_render_404_stats($s, 'dd-stats lm-ov-stats'); ?>
                </div>
            </div>
        </div>

        <?php devdlink_render_progress('dd-card', 'display:none;margin-bottom:16px;'); ?>

        <!-- ============ Trend + alerts ============ -->
        <div class="lm-bottom">
            <div class="dd-card">
                <div class="dd-sec-head"><span class="dashicons dashicons-chart-area dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Link health trend', 'devdome-link-monitor'); ?></h2></div>
                <?php if (count($trend) >= 2) :
                    // DESIGN.md section 17: stacked good/bad columns, one shared hover tooltip.
                    $max = 1;
                    foreach ($trend as $pt) {
                        $max = max($max, (int) $pt['ok_count'] + (int) $pt['broken_count']);
                    }
                    ?>
                    <div class="dd-trend" data-dd-trend>
                        <div class="dd-trend-bars">
                            <?php
                            // Always 12 slots (oldest left): runs that do not exist yet are empty grey bases.
                            $slots = array_merge(array_fill(0, max(0, 12 - count($trend)), null), $trend);
                            foreach ($slots as $pt) :
                                if ($pt === null) : ?>
                                    <div class="dd-trend-col is-empty" aria-hidden="true"><i class="dd-trend-empty"></i></div>
                                    <?php continue;
                                endif;
                                $ok = (int) $pt['ok_count'];
                                $bad = (int) $pt['broken_count'];
                                $when = $pt['finished_at'] ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($pt['finished_at'])) : '';
                                ?>
                                <div class="dd-trend-col" data-date="<?php echo esc_attr($when); ?>" data-ok="<?php echo (int) $ok; ?>" data-broken="<?php echo (int) $bad; ?>" tabindex="0" aria-label="<?php echo esc_attr(sprintf(
                                    /* translators: 1: scan date, 2: healthy count, 3: broken count. */
                                    __('%1$s: %2$d healthy, %3$d broken', 'devdome-link-monitor'),
                                    $when,
                                    $ok,
                                    $bad
                                )); ?>">
                                    <i class="dd-trend-bad" style="height:<?php echo (int) ($bad ? max(2, (int) round(100 * $bad / $max)) : 0); ?>%;"></i>
                                    <i class="dd-trend-ok" style="height:<?php echo (int) ($ok ? max(2, (int) round(100 * $ok / $max)) : 0); ?>%;"></i>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="dd-trend-tip" role="tooltip" aria-hidden="true"></div>
                    </div>
                    <p class="dd-hint" style="margin-top:8px;"><?php esc_html_e('Healthy and broken links over the last 12 scans.', 'devdome-link-monitor'); ?></p>
                <?php else : ?>
                    <p class="dd-hint" style="margin:0;"><?php esc_html_e('Run a couple of scans to see how your broken links evolve.', 'devdome-link-monitor'); ?></p>
                <?php endif; ?>
            </div>

            <div class="dd-card">
                <div class="dd-sec-head"><span class="dashicons dashicons-bell dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Alerts & account', 'devdome-link-monitor'); ?></h2><a class="lm-ov-link" href="#settings"><?php esc_html_e('Settings', 'devdome-link-monitor'); ?> &rarr;</a></div>
                <?php // Green when set up, grey when off (dashboard rule: OFF rows go grey). ?>
                <div class="lm-kv"><span><?php esc_html_e('Scheduled rescan', 'devdome-link-monitor'); ?></span><span class="<?php echo ($cadence !== 'off' && isset($cadence_labels[$cadence])) ? 'lm-on' : 'lm-off'; ?>"><?php echo esc_html(isset($cadence_labels[$cadence]) ? $cadence_labels[$cadence] : $cadence_labels['off']); ?></span></div>
                <div class="lm-kv"><span><?php esc_html_e('Email on new broken links', 'devdome-link-monitor'); ?></span><span class="<?php echo $email_on ? 'lm-on' : 'lm-off'; ?>"><?php echo $email_on ? esc_html($email_to) : esc_html__('Off', 'devdome-link-monitor'); ?></span></div>
                <?php if ($email_on) :
                    $mail_status = (string) devdlink_get_setting('email_last_status', '');
                    $mail_at = devdlink_get_int('email_last_at', 0);
                    if ($mail_status === 'sent') {
                        $mail_cls = 'lm-on';
                        /* translators: %s is a date/time. */
                        $mail_txt = sprintf(__('Sent %s', 'devdome-link-monitor'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $mail_at));
                    } elseif ($mail_status === 'failed') {
                        $mail_cls = 'lm-bad';
                        $mail_txt = __('Failed: this site cannot send email', 'devdome-link-monitor');
                    } else {
                        $mail_cls = 'lm-off';
                        $mail_txt = __('Nothing new to report yet', 'devdome-link-monitor');
                    }
                    ?>
                    <div class="lm-kv"><span><?php esc_html_e('Last summary email', 'devdome-link-monitor'); ?></span><span class="<?php echo esc_attr($mail_cls); ?>"><?php echo esc_html($mail_txt); ?></span></div>
                <?php endif; ?>
                <div class="lm-kv"><span><?php esc_html_e('DevDome account', 'devdome-link-monitor'); ?></span><span class="<?php echo !empty($conn['ok']) ? 'lm-on' : 'lm-off'; ?>"><?php echo !empty($conn['ok']) ? esc_html((string) $conn['account_id']) : esc_html__('Not connected', 'devdome-link-monitor'); ?></span></div>
                <?php if (empty($conn['ok'])) : ?>
                    <div style="margin-top:14px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
                        <a class="lm-connect-btn" href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e('Connect your DevDome account', 'devdome-link-monitor'); ?></a>
                        <span style="font-size:13px;color:#6b7280;"><?php esc_html_e('See this site in your DevDome dashboard. Everything here stays free and runs locally either way.', 'devdome-link-monitor'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

/** Live scan progress block (class hooks, not ids: it renders on the Overview AND on Broken Links). */
function devdlink_render_progress($extra_class = '', $style = 'display:none;margin-top:18px;')
{
    ?>
    <div class="lm-progress-wrap <?php echo esc_attr($extra_class); ?>" data-lm-scope="links" style="<?php echo esc_attr($style); ?>">
        <div style="display:flex;justify-content:space-between;font-size:12px;color:#6b7280;margin-bottom:6px;">
            <?php // One restrained live region: the message is announced, the ETA is not. ?>
            <span class="lm-progress-msg" role="status" aria-live="polite"><?php esc_html_e('Scanning...', 'devdome-link-monitor'); ?></span>
            <span class="lm-progress-eta" aria-hidden="true"></span>
        </div>
        <div class="lm-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="<?php esc_attr_e('Scan progress', 'devdome-link-monitor'); ?>"><span class="lm-progress-bar"></span></div>
        <div style="margin-top:8px;display:flex;gap:8px;">
            <button type="button" class="dd-btn dd-btn-sm lm-pause-btn"><?php esc_html_e('Pause', 'devdome-link-monitor'); ?></button>
            <button type="button" class="dd-btn dd-btn-sm lm-cancel-btn"><?php esc_html_e('Cancel', 'devdome-link-monitor'); ?></button>
        </div>
    </div>
    <?php
}

/** The four 404 tiles (Overview tab card + top of the 404 Monitor tab). */
function devdlink_render_404_stats($s, $class = 'dd-stats')
{
    ?>
    <div class="<?php echo esc_attr($class); ?>">
        <?php // Human/Bot colors = the Analytics plugin tiles (Visitors #1967d2, Bots #be123c); total indigo, ignored muted. ?>
        <div class="dd-stat"><div class="dd-stat-num" style="color:#ef4444;"><?php echo (int) $s['fof_paths']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('404 paths', 'devdome-link-monitor'); ?></div></div>
        <div class="dd-stat"><div class="dd-stat-num" style="color:#1967d2;"><?php echo (int) $s['fof_human_hits']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Human hits', 'devdome-link-monitor'); ?></div></div>
        <div class="dd-stat"><div class="dd-stat-num" style="color:#be123c;"><?php echo (int) $s['fof_bot_hits']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Bot hits', 'devdome-link-monitor'); ?></div></div>
        <div class="dd-stat"><div class="dd-stat-num" style="color:#6b7280;"><?php echo (int) $s['fof_ignored']; ?></div><div class="dd-stat-lbl"><?php esc_html_e('Ignored', 'devdome-link-monitor'); ?></div></div>
    </div>
    <?php
}

function devdlink_render_links_tab($s)
{
    $scan_id = devdlink_get_int('last_scan_id', 0);
    ?>
    <div class="max-w-5xl px-6 py-6">
        <section>
        <div class="dd-card">
            <?php devdlink_render_progress('', 'display:none;margin:0 0 16px;'); ?>
            <?php if (!$scan_id) : ?>
                <div class="dd-banner dd-banner-watch"><?php esc_html_e('Your content has not been scanned yet. Run a link scan to see which links are broken.', 'devdome-link-monitor'); ?></div>
            <?php else : ?>
                <div class="lm-toolbar" role="group" aria-label="<?php esc_attr_e('Filter links', 'devdome-link-monitor'); ?>" style="margin-bottom:12px;">
                    <div class="lm-pills">
                        <?php
                        // DESIGN.md section 19 pills: All first, count inside, Broken is the default view.
                        $pills = array(
                            'all'      => __('All', 'devdome-link-monitor'),
                            'broken'   => __('Broken', 'devdome-link-monitor'),
                            'redirects' => __('Redirects', 'devdome-link-monitor'),
                            'timeouts' => __('Unverified', 'devdome-link-monitor'),
                            'blocked'  => __('Blocked', 'devdome-link-monitor'),
                            'internal' => __('Internal', 'devdome-link-monitor'),
                            'external' => __('External', 'devdome-link-monitor'),
                        );
                        foreach ($pills as $k => $label) : $on = ($k === 'broken'); ?>
                            <button type="button" class="lm-pill lm-filter<?php echo $on ? ' is-on' : ''; ?>" data-filter="<?php echo esc_attr($k); ?>" aria-pressed="<?php echo $on ? 'true' : 'false'; ?>"><?php echo esc_html($label); ?> <span class="lm-pill-num" data-lm-count="<?php echo esc_attr($k); ?>">0</span></button>
                        <?php endforeach; ?>
                        <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('"Unverified" means the check could not reach a verdict (the host did not resolve, the certificate failed, the connection timed out). "Blocked" means the target answered but refused to be checked (401, 403 after a full GET, 429, 999). Neither is counted as a broken link.', 'devdome-link-monitor'); ?></span></span>
                    </div>
                    <span style="flex:1;"></span>
                    <div class="lm-cols">
                        <button type="button" class="dd-btn dd-btn-sm" id="lm-cols-btn" aria-haspopup="true" aria-expanded="false"><span class="dashicons dashicons-editor-table"></span> <?php esc_html_e('Columns', 'devdome-link-monitor'); ?></button>
                        <div class="lm-cols-panel" id="lm-cols-panel" style="display:none;">
                            <?php foreach (array('status' => __('Status', 'devdome-link-monitor'), 'page' => __('Page', 'devdome-link-monitor'), 'anchor' => __('Anchor', 'devdome-link-monitor')) as $ck => $cl) : ?>
                                <label class="lm-cols-row"><input type="checkbox" class="dd-check" data-col="<?php echo esc_attr($ck); ?>" checked> <?php echo esc_html($cl); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php // Row 2 (under the pills): selection count, Actions dropdown, Apply, search. ?>
                <div class="lm-toolbar lm-actions" style="margin:0 0 14px;">
                    <span class="lm-selcount" id="lm-selcount" aria-live="polite"><?php
                        /* translators: %d is the number of selected links. */
                        echo esc_html(sprintf(__('Links Selected (%d)', 'devdome-link-monitor'), 0)); ?></span>
                    <?php devdlink_render_dropdown('lm_bulk_action', '', array(
                        ''            => __('Actions', 'devdome-link-monitor'),
                        'recheck'     => __('Re-check selected links', 'devdome-link-monitor'),
                        'dismiss'     => __('Dismiss selected', 'devdome-link-monitor'),
                        'unlink'      => __('Unlink selected (keep the text)', 'devdome-link-monitor'),
                        'recheck_all' => __('Re-check all flagged links', 'devdome-link-monitor'),
                    ), __('Actions', 'devdome-link-monitor')); ?>
                    <button type="button" class="dd-btn dd-btn-primary" id="lm-apply" disabled><?php esc_html_e('Apply', 'devdome-link-monitor'); ?></button>
                    <span style="flex:1;"></span>
                    <label class="screen-reader-text" for="lm-links-search"><?php esc_html_e('Search URL', 'devdome-link-monitor'); ?></label>
                    <input type="search" id="lm-links-search" class="dd-input" style="width:220px;" placeholder="<?php esc_attr_e('Search URL...', 'devdome-link-monitor'); ?>">
                </div>

                <div id="lm-links-app" data-scan="<?php echo (int) $scan_id; ?>">
                    <div class="dd-list-wrap">
                    <table class="dd-table dd-list" style="width:100%;">
                        <caption class="screen-reader-text"><?php esc_html_e('Links found in your published content, with their check status', 'devdome-link-monitor'); ?></caption>
                        <thead>
                            <tr>
                                <th class="dd-th lm-col-check"><input type="checkbox" class="dd-check" id="lm-check-all" aria-label="<?php esc_attr_e('Select all links on this page', 'devdome-link-monitor'); ?>"></th>
                                <th class="dd-th" data-col="status"><?php esc_html_e('Status', 'devdome-link-monitor'); ?></th>
                                <th class="dd-th" data-col="url"><?php esc_html_e('Link', 'devdome-link-monitor'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="lm-links-tbody"></tbody>
                    </table>
                    </div>
                    <div id="lm-links-empty" class="dd-empty" style="display:none;"><?php esc_html_e('No links matched this filter.', 'devdome-link-monitor'); ?></div>
                    <div id="lm-links-pager" class="lm-pagination"></div>
                </div>
                <?php // Edit-link dialog (DESIGN.md section 20 .dd-modal): replaces the browser prompt(). ?>
                <div class="dd-modal" id="lm-edit-modal" hidden role="dialog" aria-modal="true" aria-labelledby="lm-edit-title">
                    <div class="dd-modal-backdrop" data-lm-modal-close></div>
                    <div class="dd-modal-box">
                        <div class="dd-modal-head">
                            <h3 id="lm-edit-title"><?php esc_html_e('Edit link', 'devdome-link-monitor'); ?></h3>
                            <button type="button" class="dd-modal-x" data-lm-modal-close aria-label="<?php esc_attr_e('Close', 'devdome-link-monitor'); ?>">&times;</button>
                        </div>
                        <div class="dd-modal-body">
                            <span class="dd-modal-label"><?php esc_html_e('Current URL', 'devdome-link-monitor'); ?></span>
                            <div class="dd-modal-current" id="lm-edit-current"></div>
                            <label class="dd-modal-label" for="lm-edit-input"><?php esc_html_e('New URL', 'devdome-link-monitor'); ?></label>
                            <input type="url" id="lm-edit-input" class="dd-input" style="width:100%;" autocomplete="off" spellcheck="false">
                            <button type="button" class="dd-btn dd-btn-sm" id="lm-edit-final" style="margin-top:8px;display:none;"><span class="dashicons dashicons-randomize"></span> <?php esc_html_e('Use final URL', 'devdome-link-monitor'); ?></button>
                            <span class="dd-modal-label" id="lm-edit-where-label" style="margin-top:16px;"><?php esc_html_e('Found in', 'devdome-link-monitor'); ?></span>
                            <ul class="dd-modal-list" id="lm-edit-where"></ul>
                            <p class="dd-hint" id="lm-edit-hint" style="margin:10px 0 0;"></p>
                            <div class="dd-modal-error" id="lm-edit-error" hidden></div>
                        </div>
                        <div class="dd-modal-foot">
                            <button type="button" class="dd-btn" data-lm-modal-close><?php esc_html_e('Cancel', 'devdome-link-monitor'); ?></button>
                            <button type="button" class="dd-btn dd-btn-primary" id="lm-edit-save"><?php esc_html_e('Save', 'devdome-link-monitor'); ?></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        </section>
    </div>
    <?php
}

/* ----------------------------- settings tab ----------------------------- */

function devdlink_render_settings_tab()
{
    $timeout = devdlink_get_int('check_timeout', 10);
    $chunk = devdlink_get_int('scan_chunk_size', 50);
    $batch = devdlink_get_int('check_batch_size', 15);
    $ua = (string) devdlink_get_setting('user_agent', 'Mozilla/5.0 (compatible; DevDomeLinkMonitor/' . DEVDLINK_VERSION . '; +https://devdome.com)');
    $excluded = devdlink_get_array('excluded_domains');
    $purge = devdlink_get_int('purge_days', 90);
    $sched = (string) devdlink_get_setting('scheduled_rescan', 'off');
    $email_on = devdlink_get_int('email_new_broken', 0);
    // Email summaries exist only for a connected DevDome account and go to its email only.
    $conn = function_exists('devdcorev1_connection_state') ? devdcorev1_connection_state() : array('ok' => 0, 'email' => '');
    $connect_url = devdlink_connect_url('settings');
    $referrer_mode = (string) devdlink_get_setting('referrer_mode', 'origin_path');
    $store_ua = devdlink_get_int('store_user_agent', 1);
    ?>
    <div class="max-w-5xl px-6 py-6">
        <?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own PRG redirect; nothing is processed.
        if (isset($_GET['lm_saved'])) : ?>
            <div class="dd-banner dd-banner-ok lm-saved-banner" style="margin-bottom:16px;"><?php esc_html_e('Settings saved.', 'devdome-link-monitor'); ?></div>
        <?php endif; ?>
        <form method="post">
            <?php wp_nonce_field('devdlink_settings', '_lmsn'); ?>
            <input type="hidden" name="devdlink_settings_save" value="1">

            <section style="margin-bottom:32px;">
            <div class="dd-sec-head"><span class="dashicons dashicons-search dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Scanner', 'devdome-link-monitor'); ?></h2></div>
            <div class="dd-card">
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Check timeout', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Seconds per link check. Timeouts are never counted as broken.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td"><input type="number" min="3" max="30" name="lm_check_timeout" value="<?php echo (int) $timeout; ?>" class="dd-input" style="width:90px;"> </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Posts per batch', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Posts parsed per background tick. Lower this on small hosts.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td"><input type="number" min="10" max="500" name="lm_scan_chunk_size" value="<?php echo (int) $chunk; ?>" class="dd-input" style="width:90px;"> </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Links per batch', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Links checked per background tick, at most 2 per host.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td"><input type="number" min="3" max="50" name="lm_check_batch_size" value="<?php echo (int) $batch; ?>" class="dd-input" style="width:90px;"> </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('User agent', 'devdome-link-monitor'); ?></th>
                        <td class="dd-td"><input type="text" name="lm_user_agent" value="<?php echo esc_attr($ua); ?>" class="dd-input" style="width:100%;" maxlength="255"></td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Excluded domains', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('One host per line. Links to these hosts are skipped entirely.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td">
                            <?php $excluded_n = count(array_filter(array_map('trim', (array) $excluded))); ?>
                            <div class="lm-list-count" data-lm-count-for="lm_excluded_domains" data-lm-count-label="<?php
                                /* translators: %d is the number of excluded domains. */
                                echo esc_attr__('Domains (%d)', 'devdome-link-monitor'); ?>" <?php echo $excluded_n ? '' : 'hidden'; ?>><?php
                                /* translators: %d is the number of excluded domains. */
                                echo esc_html(sprintf(__('Domains (%d)', 'devdome-link-monitor'), $excluded_n)); ?></div>
                            <textarea name="lm_excluded_domains" class="dd-textarea" rows="3" placeholder="example.com"><?php echo esc_textarea(implode("\n", (array) $excluded)); ?></textarea>
                            
                        </td>
                    </tr>
                </table>
            </div>
            </section>

            <section style="margin-bottom:32px;">
            <div class="dd-sec-head"><span class="dashicons dashicons-warning dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('404 Monitor', 'devdome-link-monitor'); ?></h2></div>
            <div class="dd-card">
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><label for="lm-select-lm_purge_days"><?php esc_html_e('Auto-purge entries', 'devdome-link-monitor'); ?></label> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Entries not seen within this window are removed daily. Ignored paths are kept longer (one year) but are capped in number too.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td">
                            <?php devdlink_render_dropdown('lm_purge_days', (int) $purge, array(
                                30  => __('After 30 days', 'devdome-link-monitor'),
                                90  => __('After 90 days', 'devdome-link-monitor'),
                                180 => __('After 180 days', 'devdome-link-monitor'),
                            ), __('Auto-purge entries', 'devdome-link-monitor')); ?>
                            
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><label for="lm-select-lm_referrer_mode"><?php esc_html_e('Store referrer', 'devdome-link-monitor'); ?></label> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Referrer query strings are NEVER stored - they can carry email addresses, tokens and session identifiers. This setting only chooses how much of the rest is kept.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td">
                            <?php devdlink_render_dropdown('lm_referrer_mode', $referrer_mode, array(
                                'origin_path' => __('Origin and path (default)', 'devdome-link-monitor'),
                                'origin'      => __('Origin only', 'devdome-link-monitor'),
                                'none'        => __('Do not store referrers', 'devdome-link-monitor'),
                            ), __('Store referrer', 'devdome-link-monitor')); ?>
                            
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Store user agent', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Used only for the bot/human split. Turning it off keeps the split working (it is computed at request time) but stores nothing.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td">
                            <label class="dd-opt"><input type="checkbox" class="dd-check" name="lm_store_user_agent" value="1" <?php checked($store_ua, 1); ?>> <?php esc_html_e('Keep the last user-agent string per path', 'devdome-link-monitor'); ?></label>

                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Bot/human split', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Classified from the user-agent string only. A user agent can be forged, so "human" means "did not match a known crawler pattern" - not proof of a real visitor. A request with no user agent at all is always counted as a bot.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td"><?php echo esc_html(function_exists('devdlink_classifier_name') ? devdlink_classifier_name() : ''); ?></td>
                    </tr>
                </table>
            </div>
            </section>

            <section style="margin-bottom:32px;">
            <div class="dd-sec-head"><span class="dashicons dashicons-clock dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Schedule', 'devdome-link-monitor'); ?></h2></div>
            <div class="dd-card">
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><label for="lm-select-lm_scheduled_rescan"><?php esc_html_e('Scheduled rescan', 'devdome-link-monitor'); ?></label> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Scheduled scans run through WP-Cron, which only fires when your site gets traffic (or a real cron job calls wp-cron.php).', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td">
                            <?php devdlink_render_dropdown('lm_scheduled_rescan', $sched, array(
                                'off'     => __('Off', 'devdome-link-monitor'),
                                'weekly'  => __('Weekly', 'devdome-link-monitor'),
                                'monthly' => __('Monthly', 'devdome-link-monitor'),
                            ), __('Scheduled rescan', 'devdome-link-monitor')); ?>
                            
                        </td>
                    </tr>
                </table>
            </div>
            </section>

            <section style="margin-bottom:32px;">
            <div class="dd-sec-head"><span class="dashicons dashicons-email dd-ico"></span><h2 class="dd-h2"><?php esc_html_e('Email', 'devdome-link-monitor'); ?></h2></div>
            <div class="dd-card">
                <table class="dd-table">
                    <tr>
                        <th class="dd-th"><?php esc_html_e('DevDome account', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Email summaries go to the email of the connected DevDome account only. Everything else in this plugin works without an account.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td">
                            <?php if (!empty($conn['ok'])) : ?>
                                <span class="dd-pill dd-pill-ok"><span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px;"></span> <?php echo esc_html(trim((string) $conn['account_id'] . (!empty($conn['email']) ? ' · ' . $conn['email'] : ''))); ?></span>
                            <?php else : ?>
                                <a class="lm-connect-btn" href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e('Connect your DevDome account', 'devdome-link-monitor'); ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="dd-th"><?php esc_html_e('Email summary', 'devdome-link-monitor'); ?> <span class="dd-tip"><span class="dashicons dashicons-info-outline"></span><span class="dd-tip-box"><?php esc_html_e('Sent by DevDome to the email of the connected account only, so alerts can never be pointed at someone else. It contains aggregate counts (links checked, broken, redirects, newly broken); scanned URLs, anchor text and post titles are never sent to DevDome.', 'devdome-link-monitor'); ?></span></span></th>
                        <td class="dd-td">
                            <?php if (!empty($conn['ok']) && !empty($conn['email'])) : ?>
                                <label class="dd-opt"><input type="checkbox" class="dd-check" name="lm_email_new_broken" value="1" <?php checked($email_on, 1); ?>> <?php esc_html_e('Email me a summary of NEW broken links after a scan, to', 'devdome-link-monitor'); ?> <strong><?php echo esc_html((string) $conn['email']); ?></strong></label>
                            <?php else : ?>
                                <label class="dd-opt" style="opacity:.6;"><input type="checkbox" class="dd-check" disabled> <?php esc_html_e('Email me a summary of NEW broken links after a scan', 'devdome-link-monitor'); ?></label>
                                <div class="dd-hint" style="display:block;margin-top:6px;"><?php esc_html_e('Connect your DevDome account above to turn on email summaries. They go to your account email only.', 'devdome-link-monitor'); ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            </section>

            <?php // Sticky save footer: 1:1 with Safe Media Cleaner's settings tab. ?>
            <footer class="dd-footer">
                <div class="dd-footer-inner">
                    <div class="dd-footer-actions">
                        <button type="submit" class="dd-btn-primary" style="gap:8px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7"/><path d="M7 3v4a1 1 0 0 0 1 1h7"/></svg><?php esc_html_e('Save Settings', 'devdome-link-monitor'); ?></button>
                    </div>
                </div>
            </footer>
        </form>
    </div>
    <?php
}

/** Nonced URL of the Broken Links CSV export (header icon on the Broken Links tab). */
function devdlink_links_export_url()
{
    return wp_nonce_url(
        add_query_arg(array('page' => DEVDLINK_PAGE, 'lm_export_links' => '1'), admin_url('admin.php')),
        'devdlink_export_links',
        '_lmexl'
    );
}

/** Stream the latest scan's flagged links (broken, redirect, unverified, blocked) as CSV. */
function devdlink_handle_export_links()
{
    if (empty($_GET['lm_export_links']) || !current_user_can(devdlink_capability('data'))) {
        return;
    }
    check_admin_referer('devdlink_export_links', '_lmexl');

    global $wpdb;
    $links   = $wpdb->prefix . 'devdlink_links';
    $scan_id = devdlink_get_int('last_scan_id', 0);
    $cell    = function ($v) {
        return function_exists('devdlink_fof_csv_cell') ? devdlink_fof_csv_cell((string) $v) : (string) $v;
    };

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Cache-Control: no-store, private, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="devdome-broken-links-' . gmdate('Y-m-d') . '.csv"');

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming CSV rows to php://output for a nonce + capability gated admin download.
    $out = fopen('php://output', 'w');
    fputcsv($out, array('url', 'status', 'reason', 'http_code', 'anchor', 'found_in', 'redirect_url', 'checked_at'));

    $last = 0;
    do {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- chunked read of the plugin's own table; ids bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, url, status, error_class, http_code, anchor_text, redirect_url, checked_at
             FROM {$links} WHERE scan_id = %d AND status IN ('broken', 'redirect', 'timeout', 'blocked') AND id > %d
             ORDER BY id ASC LIMIT %d",
            $scan_id,
            $last,
            500
        ), ARRAY_A);
        if (!$rows) {
            break;
        }
        $sources = function_exists('devdlink_rest_link_sources') ? devdlink_rest_link_sources(wp_list_pluck($rows, 'id')) : array();
        foreach ($rows as $r) {
            $last  = (int) $r['id'];
            $found = isset($sources[$last]) ? implode(' | ', wp_list_pluck($sources[$last], 'title')) : '';
            fputcsv($out, array(
                $cell($r['url']),
                $cell(function_exists('devdlink_status_label') ? devdlink_status_label((string) $r['status'], '') : $r['status']),
                $cell(function_exists('devdlink_error_class_label') ? devdlink_error_class_label((string) $r['error_class']) : $r['error_class']),
                (int) $r['http_code'],
                $cell((string) $r['anchor_text']),
                $cell($found),
                $cell((string) $r['redirect_url']),
                $cell((string) $r['checked_at']),
            ));
        }
    } while (count($rows) === 500);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
    fclose($out);
    exit;
}
add_action('admin_init', 'devdlink_handle_export_links');
