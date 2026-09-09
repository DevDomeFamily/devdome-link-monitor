/**
 * DevDome Link Monitor — admin UI behaviours (vanilla JS, no build step; matches mc-admin.js).
 * Self-guards on the elements of each screen so loading it everywhere is safe.
 */
(function () {
    'use strict';

    var CFG = window.DevdLink || {};
    var I18N = CFG.i18n || {};

    /**
     * One request. `signal` lets a caller abort a superseded request so a slow answer can
     * never overwrite newer state, and a non-JSON / network failure resolves to a normal
     * {ok:false} result instead of an unhandled rejection.
     */
    function api(path, method, body, signal) {
        // Plain-permalink sites get a rest_url() that already carries ?rest_route=…;
        // a path's own query string must then join with & instead of a second ?.
        var url = CFG.root + path;
        if (CFG.root.indexOf('?') !== -1 && path.indexOf('?') !== -1) {
            url = CFG.root + path.replace('?', '&');
        }
        return fetch(url, {
            method: method || 'GET',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
            signal: signal
        }).then(function (r) {
            return r.text().then(function (t) {
                var j = null;
                try { j = t ? JSON.parse(t) : null; } catch (err) { j = null; }
                if (j === null) {
                    return { ok: false, status: r.status, data: { message: I18N.networkError || 'The server could not be reached.' } };
                }
                return { ok: r.ok, status: r.status, data: j };
            });
        }).catch(function (err) {
            if (err && err.name === 'AbortError') { return { ok: false, aborted: true, status: 0, data: {} }; }
            return { ok: false, status: 0, data: { message: I18N.networkError || 'The server could not be reached.' } };
        });
    }

    /** Message for a failed response, mapping the status codes the REST API actually uses. */
    function errMessage(res) {
        if (res && res.data && res.data.message) { return res.data.message; }
        if (res && res.status === 403) { return I18N.noPermission || 'You do not have permission to do that.'; }
        if (res && res.status === 409) { return I18N.conflict || 'That conflicts with the current state.'; }
        return I18N.actionFailed || 'That did not work. Please try again.';
    }

    /* ---- DOM builders. Everything the server sends (URLs, anchors, post titles, paths,
     * referrers, suggestion labels, error messages) is author-controlled and therefore
     * hostile: it is only ever written with textContent or through a DOM property, never
     * concatenated into markup. There is no HTML-string escaper in this file on purpose —
     * a text escaper does not escape quotes, which is exactly how
     * `https://example.com/" onmouseover="alert(1)` became an admin XSS. ---- */
    function txt(s) { return (s === null || s === undefined) ? '' : String(s); }
    function elem(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (text !== undefined) { n.textContent = txt(text); }
        return n;
    }
    function clear(node) { while (node.firstChild) { node.removeChild(node.firstChild); } }
    /** Only http(s) may ever reach an href — javascript:, data: and friends render as text. */
    function safeUrl(u) { return /^https?:\/\//i.test(txt(u)) ? txt(u) : ''; }
    /** Fixed class per known server enum; an unknown status can never inject a class name. */
    var STATUS_CLASSES = ['pending', 'suspect', 'ok', 'broken', 'redirect', 'timeout', 'blocked', 'dismissed'];
    function statusClass(s) { return STATUS_CLASSES.indexOf(txt(s)) === -1 ? 'lm-badge-unknown' : 'lm-badge-' + txt(s); }
    function loadingRow(tbody, cols) {
        clear(tbody);
        var tr = elem('tr');
        var td = elem('td', 'dd-td');
        td.colSpan = cols;
        td.appendChild(elem('span', 'dd-hint', I18N.loading || 'Loading...'));
        tr.appendChild(td);
        tbody.appendChild(tr);
    }

    function fmtEta(sec) {
        if (sec === null || sec === undefined) { return ''; }
        if (sec < 60) { return sec + 's left'; }
        var m = Math.floor(sec / 60);
        return m + 'm ' + (sec % 60) + 's left';
    }

    /* The mouse-only custom listbox is gone: the settings screen uses native <select>
     * elements, which the platform already makes keyboard- and screen-reader-accessible. */

    /* ---- Scan progress polling. All hooks are CLASSES updated together. Progress wraps carry
     * data-lm-scope="links" (the 404 block has no jobs, so it has no wrap) — the pattern mirrors
     * media-cleaner's data-mc-scope so a future second job type slots straight in. ---- */
    var pollTimer = null;
    var polling = false;   // request-in-flight guard (ticks can take >1 interval)
    var cancelled = false; // set the instant the user hits Cancel — ignore late responses
    var jobScope = '';     // scope of the job we started / resumed ('' = show everywhere)
    function wrapMatches(wrap) {
        var ws = wrap.getAttribute('data-lm-scope');
        if (!ws || !jobScope) { return true; }
        return ws === jobScope;
    }
    /** Reload for fresh numbers but land on the tab the user is on (the switcher honours the hash). */
    function reloadKeepTab() {
        var t = document.querySelector('.dd-tab.is-active[data-dd-tab]');
        if (t) { location.hash = t.getAttribute('data-dd-tab'); }
        location.reload();
    }
    function showProgress(show) {
        document.querySelectorAll('.lm-progress-wrap').forEach(function (wrap) {
            wrap.style.display = (show && wrapMatches(wrap)) ? 'block' : 'none';
            wrap.classList.remove('is-done');
        });
    }
    function setProgress(p) {
        document.querySelectorAll('.lm-progress[role="progressbar"]').forEach(function (bar) {
            bar.setAttribute('aria-valuenow', String(parseInt(p.percent, 10) || 0));
        });
        // Server says paused (page reopened mid-pause, or the chunk boundary honoured a
        // pause): make the button state match — data-paused + Resume label + Paused message.
        // One-directional on purpose: the optimistic click flip stays untouched while the
        // server still reports 'running'.
        if (p.status === 'paused') {
            document.querySelectorAll('.lm-pause-btn').forEach(function (b) {
                b.setAttribute('data-paused', '1');
                b.textContent = I18N.resume || 'Resume';
            });
        }
        document.querySelectorAll('.lm-progress-bar').forEach(function (bar) {
            bar.style.width = (p.percent || 0) + '%';
        });
        document.querySelectorAll('.lm-progress-msg').forEach(function (msg) {
            var counts = (p.total && p.processed !== null && p.processed !== undefined) ? ' (' + p.processed + '/' + p.total + ')' : '';
            var text = (p.status === 'paused') ? (I18N.paused || 'Paused') : (p.message || I18N.scanning || 'Working...');
            msg.textContent = text + counts;
        });
        document.querySelectorAll('.lm-progress-eta').forEach(function (eta) {
            eta.textContent = fmtEta(p.eta);
        });
    }
    function markDone(label) {
        document.querySelectorAll('.lm-progress-wrap').forEach(function (wrap) { wrap.classList.add('is-done'); });
        document.querySelectorAll('.lm-progress-bar').forEach(function (bar) { bar.style.width = '100%'; });
        document.querySelectorAll('.lm-progress-msg').forEach(function (msg) { msg.textContent = label || 'Completed'; });
        document.querySelectorAll('.lm-progress-eta').forEach(function (eta) { eta.textContent = ''; });
        document.querySelectorAll('.lm-pause-btn, .lm-cancel-btn').forEach(function (b) { b.style.display = 'none'; });
    }
    function stopPolling() {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
        polling = false;
    }
    /**
     * Drive + observe the job.
     *
     * GET scan-progress is READ ONLY on the server, so the work is claimed explicitly with
     * POST scan-tick. Nothing runs while the tab is hidden (the timer keeps checking but
     * makes no request), and the interval backs off while the job is idle/waiting so an open
     * tab is not a load generator.
     */
    function poll(onDone) {
        if (pollTimer) { return; }
        cancelled = false;
        showProgress(true);
        var interval = 700;
        var schedule = function () {
            if (cancelled) { return; }
            pollTimer = setTimeout(tick, interval);
        };
        var tick = function () {
            pollTimer = null;
            if (polling || cancelled) { schedule(); return; }
            if (document.hidden) { schedule(); return; } // a hidden tab does no work at all
            polling = true;
            // POST is the work claim; its response is the fresh progress snapshot.
            api('scan-tick', 'POST').then(function (res) {
                polling = false;
                if (cancelled || res.aborted) { return; }
                if (!res.ok) {
                    // Not allowed to drive the queue (or the server is unhappy): fall back to
                    // watching the read-only endpoint instead of hammering the write one.
                    interval = 3000;
                    api('scan-progress').then(function (r2) {
                        if (cancelled) { return; }
                        finish(r2.data || {});
                    });
                    return;
                }
                finish(res.data || {});
            });
        };
        var finish = function (p) {
            setProgress(p);
            if (!p.active && p.status !== 'running') {
                stopPolling();
                if (p.status === 'completed') {
                    // Green, explicit, visible — then refresh the stats.
                    markDone(I18N.completed || 'Completed');
                    setTimeout(function () { if (onDone) { onDone(p); } }, 1400);
                } else {
                    showProgress(false);
                    if (onDone) { onDone(p); }
                }
                return;
            }
            // The server confirmed the pause: the bar stays up in its paused state (Resume +
            // Cancel visible) and polling STOPS, so a paused job makes no requests at all.
            // Resume restarts the poller (see the pause button below).
            if (p.status === 'paused') {
                stopPolling();
                return;
            }
            interval = 700;
            schedule();
        };
        tick(); // fire IMMEDIATELY — never make the user wait for the first interval
    }
    /** Fresh controls for a fresh job: Pause label + both buttons visible (a finished job hides them). */
    function resetJobControls() {
        document.querySelectorAll('.lm-pause-btn').forEach(function (b) {
            b.setAttribute('data-paused', '0');
            b.textContent = I18N.pause || 'Pause';
            b.style.display = '';
        });
        document.querySelectorAll('.lm-cancel-btn').forEach(function (b) { b.style.display = ''; });
    }

    /* ---- Overview: scan / re-check / retry buttons. ---- */
    // Class-bound: Scan lives on the Overview AND on Broken Links, Retry in the error banner.
    var jobButtons = Array.prototype.slice.call(document.querySelectorAll('.lm-job-btn'));
    function setJobButtons(disabled) { jobButtons.forEach(function (b) { b.disabled = disabled; }); }
    function startJob(mode) {
        setJobButtons(true);
        jobScope = 'links';
        // Instant feedback: the bar appears NOW, not after the server answers.
        resetJobControls();
        showProgress(true);
        setProgress({ percent: 0, message: I18N.starting || 'Starting...' });
        api('start-scan', 'POST', { mode: mode || 'full' }).then(function (res) {
            if (!res.ok) {
                showProgress(false);
                setJobButtons(false);
                alert(errMessage(res) || I18N.couldNotStart || 'Could not start.');
                return;
            }
            poll(reloadKeepTab);
        });
    }
    jobButtons.forEach(function (btn) {
        btn.addEventListener('click', function () { startJob(btn.getAttribute('data-mode') || 'full'); });
    });
    document.querySelectorAll('.lm-pause-btn').forEach(function (pauseBtn) {
        pauseBtn.addEventListener('click', function () {
            var paused = pauseBtn.getAttribute('data-paused') === '1';
            // Optimistic flip — the server honours it at the next chunk boundary.
            pauseBtn.setAttribute('data-paused', paused ? '0' : '1');
            pauseBtn.textContent = paused ? (I18N.pause || 'Pause') : (I18N.resume || 'Resume');
            if (!paused) {
                document.querySelectorAll('.lm-progress-msg').forEach(function (m) { m.textContent = I18N.pausing || 'Pausing after the current batch...'; });
            }
            api('job-control', 'POST', { action: paused ? 'resume' : 'pause' }).then(function (res) {
                // Resume: the poller stopped when the pause was confirmed, so start it again
                // once the server has flipped the job back to running (only if it is not
                // already looping, i.e. the pause was never confirmed before Resume).
                if (paused && !cancelled && !pollTimer && !polling) {
                    if (res && res.ok && res.data) { setProgress(res.data); }
                    poll(reloadKeepTab);
                }
            });
        });
    });
    document.querySelectorAll('.lm-cancel-btn').forEach(function (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            // INSTANT: hide first, tell the server in the background.
            cancelled = true;
            stopPolling();
            showProgress(false);
            setJobButtons(false);
            api('job-control', 'POST', { action: 'cancel' });
        });
    });

    /* ---- If a job is already running (page reopened mid-scan), resume the live bar. A job
     * reopened mid-PAUSE renders the paused state (Resume + Cancel) and does not poll. ---- */
    if (document.querySelector('.lm-progress-wrap')) {
        api('scan-progress').then(function (res) {
            var p = res.data || {};
            if (p.active) {
                setJobButtons(true);
                jobScope = 'links';
                setProgress(p);
                if (p.status === 'paused') {
                    showProgress(true);
                } else {
                    poll(reloadKeepTab);
                }
            }
        });
    }

    /* ---- Settings: live "(n)" counter next to list textareas. ---- */
    document.querySelectorAll('[data-lm-count-for]').forEach(function (badge) {
        var ta = document.querySelector('[name="' + badge.getAttribute('data-lm-count-for') + '"]');
        if (!ta) { return; }
        var tpl = badge.getAttribute('data-lm-count-label') || (I18N.domainsCount || 'Domains (%d)');
        var update = function () {
            var n = ta.value.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; }).length;
            badge.textContent = tpl.replace('%d', String(n));
            if (n > 0) { badge.removeAttribute('hidden'); } else { badge.setAttribute('hidden', 'hidden'); }
        };
        ta.addEventListener('input', update);
        update();
    });

    /* ---- Forms: Enter inside a text/number field must not submit (User agent, timeouts...).
     * Only the Save button saves; textareas keep their newline. ---- */
    document.querySelectorAll('.dd-app form').forEach(function (form) {
        form.addEventListener('keydown', function (e) {
            var t = e.target;
            if (e.key === 'Enter' && t && t.tagName === 'INPUT' && t.type !== 'submit' && t.type !== 'button') {
                e.preventDefault();
            }
        });
    });

    /* ---- Trend chart tooltip (DESIGN.md section 17): one shared tip per chart, filled from the
     * column's data-* values, positioned above the hovered/focused column. ---- */
    document.querySelectorAll('[data-dd-trend]').forEach(function (chart) {
        var tip = chart.querySelector('.dd-trend-tip');
        if (!tip) { return; }
        var series = [['ok', I18N.healthy || 'Healthy', '#10b981'], ['broken', I18N.broken || 'Broken', '#ef4444']];
        var show = function (col) {
            clear(tip);
            tip.appendChild(elem('span', 'dd-trend-date', col.getAttribute('data-date') || ''));
            series.forEach(function (sr) {
                var row = elem('div', 'dd-trend-row');
                var label = elem('span');
                var swatch = elem('i', 'dd-trend-swatch');
                swatch.style.background = sr[2];
                label.appendChild(swatch);
                label.appendChild(document.createTextNode(sr[1]));
                row.appendChild(label);
                row.appendChild(elem('span', 'dd-trend-val', col.getAttribute('data-' + sr[0]) || '0'));
                tip.appendChild(row);
            });
            // Anchor to the top of the drawn bar (first segment), not the column box: the
            // column is full-height, the bar is not.
            var cr = col.getBoundingClientRect();
            var br = (col.firstElementChild || col).getBoundingClientRect();
            var pr = chart.getBoundingClientRect();
            tip.style.left = Math.round(cr.left - pr.left + cr.width / 2) + 'px';
            tip.style.top = Math.round(br.top - pr.top - 6) + 'px';
            tip.classList.add('is-on');
            tip.setAttribute('aria-hidden', 'false');
        };
        var hide = function () { tip.classList.remove('is-on'); tip.setAttribute('aria-hidden', 'true'); };
        chart.querySelectorAll('.dd-trend-col').forEach(function (col) {
            col.addEventListener('mouseenter', function () { show(col); });
            col.addEventListener('focus', function () { show(col); });
            col.addEventListener('mouseleave', hide);
            col.addEventListener('blur', hide);
        });
    });

    /* ---- Inline SVG icons (lucide): built with DOM calls, never innerHTML. ---- */
    function svgIcon(parts) {
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 24 24');
        svg.setAttribute('fill', 'none');
        svg.setAttribute('stroke', 'currentColor');
        svg.setAttribute('stroke-width', '2');
        svg.setAttribute('stroke-linecap', 'round');
        svg.setAttribute('stroke-linejoin', 'round');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('class', 'lm-svg');
        parts.forEach(function (p) {
            var el = document.createElementNS(ns, p[0]);
            Object.keys(p[1]).forEach(function (k) { el.setAttribute(k, p[1][k]); });
            svg.appendChild(el);
        });
        return svg;
    }
    var ICON_COPY = [['rect', { width: '14', height: '14', x: '8', y: '8', rx: '2', ry: '2' }], ['path', { d: 'M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2' }]];
    var ICON_CHECK = [['path', { d: 'M20 6 9 17l-5-5' }]];
    function fallbackCopy(text) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', 'readonly');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) { /* nothing to do */ }
        document.body.removeChild(ta);
    }
    /** Copy-to-clipboard button: copy icon, flips to a check for 1.2s. */
    function copyButton(text) {
        var b = elem('button', 'lm-copy-btn');
        b.type = 'button';
        b.setAttribute('aria-label', I18N.copyUrl || 'Copy URL');
        b.appendChild(svgIcon(ICON_COPY));
        b.addEventListener('click', function () {
            var done = function () {
                clear(b);
                b.appendChild(svgIcon(ICON_CHECK));
                b.classList.add('is-done');
                setTimeout(function () { clear(b); b.appendChild(svgIcon(ICON_COPY)); b.classList.remove('is-done'); }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text); done(); });
            } else {
                fallbackCopy(text);
                done();
            }
        });
        return b;
    }

    /* ---- Shared pager renderer. ---- */
    function renderPager(node, data, page, go, per, setPer) {
        clear(node);
        var pages = parseInt(data.pages, 10) || 1;
        var total = parseInt(data.total, 10) || 0;
        var perN = parseInt(per || data.per_page, 10) || 20;
        if (!total) { return; }
        var first = (page - 1) * perN + 1;
        var last = Math.min(total, page * perN);
        node.appendChild(elem('span', 'lm-pg-count', (I18N.ofTotal || '%1$s-%2$s of %3$s').replace('%1$s', String(first)).replace('%2$s', String(last)).replace('%3$s', String(total))));
        var right = elem('div', 'lm-pg-right');

        // Per-page menu: the dashboard's custom menu (opens upward).
        if (setPer) {
            var perWrap = elem('div', 'lm-pg-per');
            perWrap.appendChild(elem('span', '', I18N.perPage || 'Show per page:'));
            var jump = elem('div', 'page-jump-wrap');
            var jb = elem('button', 'page-btn page-jump-btn', perN + ' \u25BE');
            jb.type = 'button';
            jb.setAttribute('aria-haspopup', 'true');
            jb.setAttribute('aria-expanded', 'false');
            var menu = elem('div', 'page-jump-menu hidden');
            [10, 20, 50, 100, 200].forEach(function (n) {
                var it = elem('button', 'pjm-item' + (n === perN ? ' active' : ''), String(n));
                it.type = 'button';
                it.addEventListener('click', function () { menu.classList.add('hidden'); setPer(n); });
                menu.appendChild(it);
            });
            jb.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = menu.classList.contains('hidden');
                menu.classList.toggle('hidden', !open);
                jb.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function () { menu.classList.add('hidden'); jb.setAttribute('aria-expanded', 'false'); });
            jump.appendChild(jb);
            jump.appendChild(menu);
            perWrap.appendChild(jump);
            right.appendChild(perWrap);
        }

        if (pages > 1) {
            var btns = elem('div', 'lm-pg-btns');
            var mk = function (label, target, disabled, active) {
                var b = elem('button', 'page-btn' + (active ? ' active' : ''), label);
                b.type = 'button';
                b.disabled = !!disabled;
                if (!disabled && !active) { b.addEventListener('click', function () { go(target); }); }
                return b;
            };
            btns.appendChild(mk('\u2039', page - 1, page <= 1));
            // Window: first, last, current +/- 1, gaps between.
            var list = [];
            for (var p = 1; p <= pages; p++) {
                if (p === 1 || p === pages || Math.abs(p - page) <= 1) { list.push(p); }
            }
            var prev = 0;
            list.forEach(function (p) {
                if (p - prev > 1) { btns.appendChild(elem('span', 'page-gap', '\u2026')); }
                btns.appendChild(mk(String(p), p, false, p === page));
                prev = p;
            });
            btns.appendChild(mk('\u203A', page + 1, page >= pages));
            right.appendChild(btns);
        }
        node.appendChild(right);
    }

    /* ---- Broken Links review table. ---- */
    var linksApp = document.getElementById('lm-links-app');
    if (linksApp) {
        var lTbody = document.getElementById('lm-links-tbody');
        var lEmpty = document.getElementById('lm-links-empty');
        var lPager = document.getElementById('lm-links-pager');
        var lSearch = document.getElementById('lm-links-search');
        var lScanId = parseInt(linksApp.getAttribute('data-scan'), 10) || 0;
        var lFilter = 'broken';
        var lPage = 1;
        var lPer = 20;
        var lQuery = '';
        var searchTimer = null;
        // id -> current URL. The URL never goes into a data-* attribute: the edit prompt reads
        // it from here, so a hostile URL has no attribute context to break out of.
        var lUrlById = {};
        var lRowById = {};

        // Request sequencing: a superseded list request is aborted and, if it answers anyway,
        // its sequence number no longer matches so it cannot overwrite newer state.
        var lSeq = 0;
        var lAbort = null;
        // Every view already fetched is kept: switching back renders instantly, then refreshes silently.
        var lCache = {};
        var lPainted = false;

        function loadLinks() {
            var params = new URLSearchParams();
            params.set('scan_id', lScanId);
            params.set('filter', lFilter);
            params.set('page', lPage);
            params.set('per_page', lPer);
            if (lQuery) { params.set('search', lQuery); }
            var key = params.toString();
            if (lCache[key]) {
                renderLinks(lCache[key]);
            } else if (!lPainted) {
                loadingRow(lTbody, 6);
            }
            linksApp.classList.add('is-loading');
            if (lAbort) { lAbort.abort(); }
            lAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var mySeq = ++lSeq;
            api('links?' + params.toString(), 'GET', null, lAbort ? lAbort.signal : undefined).then(function (res) {
                if (mySeq !== lSeq) { return; }
                if (res.aborted) { return; }
                linksApp.classList.remove('is-loading');
                if (!res.ok) {
                    clear(lTbody);
                    var tr = elem('tr');
                    var td = elem('td', 'dd-td');
                    td.colSpan = 6;
                    td.appendChild(elem('span', 'dd-hint', errMessage(res)));
                    tr.appendChild(td);
                    lTbody.appendChild(tr);
                    clear(lPager);
                    return;
                }
                lCache[key] = res.data || {};
                lPainted = true;
                renderLinks(lCache[key]);
            });
        }

        function statusBadge(it) {
            var words = I18N.badge || {};
            return elem('span', 'lm-badge ' + statusClass(it.status), words[txt(it.status)] || it.status_short || it.status_label || it.status);
        }

        function editButton(id) {
            var b = elem('button', 'lm-edit-btn');
            b.type = 'button';
            b.setAttribute('data-lm-act', 'edit');
            b.setAttribute('data-id', String(parseInt(id, 10) || 0));
            b.setAttribute('aria-label', I18N.editUrl || 'Edit URL');
            b.appendChild(elem('span', 'dashicons dashicons-edit'));
            return b;
        }

        /** Under the badge: the HTTP code as a colour pill (2xx green, 3xx amber, 4xx/5xx red), then a short fact. */
        function appendStatusDetail(td, it) {
            var code = parseInt(it.http_code, 10) || 0;
            var hops = parseInt(it.redirect_hops, 10) || 0;
            if (code) {
                var cls = code >= 400 ? 'lm-code-bad' : code >= 300 ? 'lm-code-warn' : code >= 200 ? 'lm-code-ok' : 'lm-code-muted';
                td.appendChild(elem('span', 'lm-code ' + cls, String(code)));
            }
            var text = '';
            if (it.status === 'redirect' && hops) {
                text = hops + ' ' + (hops === 1 ? (I18N.hop || 'hop') : (I18N.hops || 'hops'));
            } else if (!code) {
                text = txt(it.status_detail);
            }
            if (text) { td.appendChild(elem('span', 'dd-hint lm-status-detail', text)); }
        }

        function renderLinks(data) {
            clear(lTbody);
            lUrlById = {};
            lRowById = {};
            lSelected = {};
            if (data.counts) { fillCounts(data.counts); }
            var items = data.items || [];
            if (!items.length) {
                lEmpty.style.display = 'block';
                clear(lPager);
                syncBulk();
                return;
            }
            lEmpty.style.display = 'none';
            items.forEach(function (it) {
                var id = parseInt(it.id, 10) || 0;
                lUrlById[it.id] = txt(it.url);
                lRowById[it.id] = it;
                var tr = elem('tr');

                var checkTd = elem('td', 'dd-td lm-col-check');
                var cb = elem('input', 'dd-check lm-row-check');
                cb.type = 'checkbox';
                cb.setAttribute('data-id', String(id));
                cb.setAttribute('aria-label', I18N.selectRow || 'Select this link');
                checkTd.appendChild(cb);
                tr.appendChild(checkTd);

                var statusTd = elem('td', 'dd-td');
                statusTd.setAttribute('data-col', 'status');
                statusTd.appendChild(statusBadge(it));
                appendStatusDetail(statusTd, it);
                tr.appendChild(statusTd);

                var urlTd = elem('td', 'dd-td');
                urlTd.setAttribute('data-col', 'url');
                var href = safeUrl(it.url);
                var urlNode = href ? elem('a', 'lm-url', it.url) : elem('span', 'lm-url', it.url);
                if (href) {
                    urlNode.href = href;
                    urlNode.target = '_blank';
                    urlNode.rel = 'noopener';
                }
                var urlRow = elem('div', 'lm-url-row');
                urlRow.appendChild(urlNode);
                urlRow.appendChild(copyButton(txt(it.url)));
                urlRow.appendChild(editButton(it.id));
                urlTd.appendChild(urlRow);
                urlTd.appendChild(elem('span', 'dd-hint', txt(it.host) + (it.internal ? ' · internal' : '')));
                if (it.status === 'redirect' && it.redirect_url) {
                    urlTd.appendChild(elem('span', 'dd-hint lm-url', '→ ' + txt(it.redirect_url)));
                }

                // Importance order under the link: Page (where to fix it), then Anchor (context).
                var pageLine = elem('span', 'lm-meta');
                pageLine.setAttribute('data-col', 'page');
                pageLine.appendChild(elem('b', '', (I18N.page || 'Page') + ':'));
                var sources = it.sources || [];
                if (!sources.length) {
                    pageLine.appendChild(document.createTextNode('-'));
                } else {
                    var LIMIT = 10;
                    sources.forEach(function (sp, i) {
                        var edit = safeUrl(sp.edit_url);
                        var chipText = txt(sp.title) + (sp.usage === 'img' ? ' (' + (I18N.image || 'image') + ')' : '') + ((parseInt(sp.occurrences, 10) || 1) > 1 ? ' \u00d7' + sp.occurrences : '');
                        var chip = edit ? elem('a', 'lm-page', chipText) : elem('span', 'lm-page', chipText);
                        if (edit) { chip.href = edit; chip.target = '_blank'; chip.rel = 'noopener'; }
                        if (i >= LIMIT) { chip.setAttribute('hidden', 'hidden'); chip.setAttribute('data-lm-extra', '1'); }
                        pageLine.appendChild(chip);
                    });
                    if (sources.length > LIMIT) {
                        var more = elem('button', 'lm-more', (I18N.showMore || 'Show %d more').replace('%d', String(sources.length - LIMIT)));
                        more.type = 'button';
                        more.addEventListener('click', function () {
                            var open = more.getAttribute('data-open') === '1';
                            pageLine.querySelectorAll('[data-lm-extra]').forEach(function (c) {
                                if (open) { c.setAttribute('hidden', 'hidden'); } else { c.removeAttribute('hidden'); }
                            });
                            more.setAttribute('data-open', open ? '0' : '1');
                            more.textContent = open ? (I18N.showMore || 'Show %d more').replace('%d', String(sources.length - LIMIT)) : (I18N.showLess || 'Show less');
                        });
                        pageLine.appendChild(more);
                    }
                }
                urlTd.appendChild(pageLine);

                var anchorLine = elem('span', 'lm-meta');
                anchorLine.setAttribute('data-col', 'anchor');
                anchorLine.appendChild(elem('b', '', (I18N.anchor || 'Anchor') + ':'));
                // Every distinct anchor text this URL is linked with (per occurrence), else the row's.
                var anchors = [];
                (it.sources || []).forEach(function (sp) { var a = txt(sp.anchor).trim(); if (a && anchors.indexOf(a) === -1) { anchors.push(a); } });
                if (!anchors.length && it.anchor) { anchors.push(txt(it.anchor)); }
                anchorLine.appendChild(document.createTextNode(anchors.length ? anchors.join(' | ') : '-'));
                urlTd.appendChild(anchorLine);
                tr.appendChild(urlTd);

                applyCols(tr);
                lTbody.appendChild(tr);
            });
            syncBulk();
            renderPager(lPager, data, lPage, function (target) { lPage = target; loadLinks(); }, lPer, function (n) { lPer = n; lPage = 1; loadLinks(); });
        }

        // Scoped to THIS list: the 404 list has its own pills.
        var linkPills = Array.prototype.slice.call(document.querySelectorAll('.lm-filter[data-filter]'));
        linkPills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                linkPills.forEach(function (c) {
                    c.classList.remove('is-on');
                    c.setAttribute('aria-pressed', 'false');
                });
                pill.classList.add('is-on');
                pill.setAttribute('aria-pressed', 'true');
                lFilter = pill.getAttribute('data-filter') || 'broken';
                lPage = 1;
                loadLinks();
            });
        });
        function fillCounts(c) {
            document.querySelectorAll('[data-lm-count]').forEach(function (el) {
                var k = el.getAttribute('data-lm-count');
                if (c && c[k] !== undefined) { el.textContent = String(c[k]); }
            });
        }

        /* ---- Column visibility (Columns menu), remembered per browser. ---- */
        var colsBtn = document.getElementById('lm-cols-btn');
        var colsPanel = document.getElementById('lm-cols-panel');
        var lCols = {};
        try { lCols = JSON.parse(localStorage.getItem('devdlink_cols') || '{}') || {}; } catch (err) { lCols = {}; }
        function colOn(k) { return lCols[k] !== false; }
        function applyCols(root) {
            (root || linksApp).querySelectorAll('table [data-col]').forEach(function (el) {
                el.classList.toggle('lm-col-hidden', !colOn(el.getAttribute('data-col')));
            });
        }
        if (colsBtn && colsPanel) {
            colsPanel.querySelectorAll('input[data-col]').forEach(function (cb) {
                cb.checked = colOn(cb.getAttribute('data-col'));
                cb.addEventListener('change', function () {
                    lCols[cb.getAttribute('data-col')] = cb.checked;
                    try { localStorage.setItem('devdlink_cols', JSON.stringify(lCols)); } catch (err) { /* private mode */ }
                    applyCols();
                });
            });
            colsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = colsPanel.style.display === 'none';
                colsPanel.style.display = open ? '' : 'none';
                colsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function (e) {
                if (!colsPanel.contains(e.target) && e.target !== colsBtn) {
                    colsPanel.style.display = 'none';
                    colsBtn.setAttribute('aria-expanded', 'false');
                }
            });
            applyCols();
        }

        /* ---- Selection + bulk actions (Analytics dashboard "Delete selected (n)" twin). ---- */
        var lSelected = {};
        var bulkWrap = null;
        var checkAll = document.getElementById('lm-check-all');
        var selCount = document.getElementById('lm-selcount');
        var applyBtn = document.getElementById('lm-apply');
        var actionInput = document.querySelector('input[name="lm_bulk_action"]');
        function currentAction() { return actionInput ? actionInput.value : ''; }
        function syncApply() {
            if (!applyBtn) { return; }
            var act = currentAction();
            var n = selectedIds().length;
            applyBtn.disabled = !act || (act !== 'recheck_all' && n === 0);
        }
        if (actionInput) { actionInput.addEventListener('change', syncApply); }
        function selectedIds() {
            return Object.keys(lSelected).filter(function (k) { return lSelected[k]; }).map(function (k) { return parseInt(k, 10) || 0; }).filter(Boolean);
        }
        function syncBulk() {
            var n = selectedIds().length;
            if (selCount) { selCount.textContent = (I18N.linksSelected || 'Links Selected (%d)').replace('%d', String(n)); }
            syncApply();
            if (bulkWrap) {
                bulkWrap.style.display = n ? '' : 'none';
                bulkWrap.querySelectorAll('[data-lm-bulk]').forEach(function (b) {
                    b.textContent = (b.getAttribute('data-label') || '%d').replace('%d', String(n));
                });
            }
            if (checkAll) {
                var boxes = lTbody.querySelectorAll('.lm-row-check');
                checkAll.checked = boxes.length > 0 && n === boxes.length;
                checkAll.indeterminate = n > 0 && n < boxes.length;
            }
        }
        lTbody.addEventListener('change', function (e) {
            var cb = e.target.closest('.lm-row-check');
            if (!cb) { return; }
            lSelected[cb.getAttribute('data-id')] = cb.checked;
            syncBulk();
        });
        if (checkAll) {
            checkAll.addEventListener('change', function () {
                lTbody.querySelectorAll('.lm-row-check').forEach(function (cb) {
                    cb.checked = checkAll.checked;
                    lSelected[cb.getAttribute('data-id')] = checkAll.checked;
                });
                syncBulk();
            });
        }
        function runBulk(act, ids) {
            applyBtn.disabled = true;
            var i = 0;
            (function next() {
                if (i >= ids.length) { lCache = {}; loadLinks(); return; }
                api(act === 'recheck' ? 'recheck-link' : act, 'POST', { link_id: ids[i++] }).then(next);
            })();
        }
        if (applyBtn) {
            applyBtn.addEventListener('click', function () {
                var act = currentAction();
                var ids = selectedIds();
                if (act === 'recheck_all') { startJob('recheck'); return; }
                if (!act || !ids.length) { return; }
                var msg = (act === 'unlink') ? (I18N.confirmUnlinkMany || 'Remove %d links but keep their anchor text?')
                    : (act === 'dismiss') ? (I18N.confirmDismissMany || 'Dismiss %d links?') : '';
                if (msg && !confirm(msg.replace('%d', String(ids.length)))) { return; }
                runBulk(act, ids);
            });
        }

        if (lSearch) {
            lSearch.addEventListener('input', function () {
                if (searchTimer) { clearTimeout(searchTimer); }
                searchTimer = setTimeout(function () {
                    lQuery = lSearch.value.trim();
                    lPage = 1;
                    loadLinks();
                }, 350);
            });
        }

        /* ---- Edit-link dialog (DESIGN.md section 20): current URL, new URL, "Use final URL" for
         * redirects, the "what will change" hint, errors inline. Replaces prompt()/alert(). ---- */
        var editModal = document.getElementById('lm-edit-modal');
        var editInput = document.getElementById('lm-edit-input');
        var editCurrent = document.getElementById('lm-edit-current');
        var editHint = document.getElementById('lm-edit-hint');
        var editError = document.getElementById('lm-edit-error');
        var editFinal = document.getElementById('lm-edit-final');
        var editSave = document.getElementById('lm-edit-save');
        var editWhere = document.getElementById('lm-edit-where');
        var editId = 0;
        function showNotice(text, ok) {
            var old = linksApp.querySelector('.lm-notice');
            if (old) { old.parentNode.removeChild(old); }
            var n = elem('div', 'dd-banner ' + (ok ? 'dd-banner-ok' : 'dd-banner-error') + ' lm-notice', text);
            linksApp.insertBefore(n, linksApp.firstChild);
            setTimeout(function () { if (n.parentNode) { n.parentNode.removeChild(n); } }, 6000);
        }
        function closeEditModal() {
            if (!editModal) { return; }
            editModal.setAttribute('hidden', 'hidden');
            editId = 0;
        }
        function editFail(text) {
            editError.textContent = text;
            editError.removeAttribute('hidden');
            editSave.disabled = false;
            editSave.textContent = I18N.save || 'Save';
        }
        function openEditModal(id) {
            if (!editModal) { return; }
            var row = lRowById[id] || {};
            var current = lUrlById[id] || txt(row.url);
            editId = id;
            editCurrent.textContent = current;
            editInput.value = current;
            editError.setAttribute('hidden', 'hidden');
            editError.textContent = '';
            editSave.disabled = false;
            editSave.textContent = I18N.save || 'Save';
            var finalUrl = (row.status === 'redirect') ? safeUrl(row.redirect_url) : '';
            editFinal.style.display = finalUrl ? '' : 'none';
            editFinal.onclick = function () { editInput.value = finalUrl; editInput.focus(); };
            var sources = row.sources || [];
            clear(editWhere);
            if (sources.length) {
                sources.forEach(function (sp) {
                    var li = elem('li');
                    li.appendChild(elem('span', 'lm-src-type', (sp.type || 'Post') + (sp.usage === 'img' ? ' \u00b7 ' + (I18N.image || 'image') : '')));
                    var edit = safeUrl(sp.edit_url);
                    var t = edit ? elem('a', 'lm-src-title', sp.title) : elem('span', 'lm-src-title', sp.title);
                    if (edit) { t.href = edit; t.target = '_blank'; t.rel = 'noopener'; t.title = I18N.edit || 'Edit'; }
                    li.appendChild(t);
                    var view = safeUrl(sp.view_url);
                    if (view) {
                        var v = elem('a', 'lm-src-view', I18N.view || 'View');
                        v.href = view; v.target = '_blank'; v.rel = 'noopener';
                        li.appendChild(v);
                    }
                    editWhere.appendChild(li);
                });
                editHint.textContent = (I18N.editHintMany || 'Saving rewrites this link in the %d item(s) above. Only exact matches are changed; WordPress keeps a revision of each one.')
                    .replace('%d', String(sources.length));
            } else {
                editWhere.appendChild(elem('li', 'is-empty', I18N.nowhere || 'Not found in any post, page or product.'));
                editHint.textContent = I18N.editHintNone || 'This link was not found in any content at the last scan; saving only updates the tracked record.';
            }
            editModal.removeAttribute('hidden');
            setTimeout(function () { editInput.focus(); editInput.select(); }, 0);
        }
        function submitEdit() {
            if (!editId) { return; }
            var current = lUrlById[editId] || '';
            var nu = editInput.value.trim();
            if (!/^https?:\/\//i.test(nu)) { editFail(I18N.editInvalid || 'Enter a full http(s) URL.'); return; }
            if (nu === current) { editFail(I18N.editSame || 'That is the current URL.'); return; }
            editSave.disabled = true;
            editSave.textContent = I18N.saving || 'Saving...';
            var id = editId;
            api('edit-url', 'POST', { link_id: id, new_url: nu }).then(function (res) {
                if (!res.ok) { editFail(errMessage(res)); return; }
                closeEditModal();
                lCache = {};
                if (I18N.postsUpdated) {
                    showNotice(I18N.postsUpdated.replace('%d', String((res.data && res.data.updated_posts) || 0)), true);
                }
                loadLinks();
            });
        }
        if (editModal) {
            editModal.querySelectorAll('[data-lm-modal-close]').forEach(function (el) { el.addEventListener('click', closeEditModal); });
            editSave.addEventListener('click', submitEdit);
            editInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submitEdit(); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !editModal.hasAttribute('hidden')) { closeEditModal(); } });
        }

        lTbody.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-lm-act]');
            if (!btn) { return; }
            var act = btn.getAttribute('data-lm-act');
            var id = parseInt(btn.getAttribute('data-id'), 10) || 0;
            if (!id) { return; }

            if (act === 'recheck') {
                btn.disabled = true;
                btn.textContent = I18N.rechecking || 'Checking...';
                api('recheck-link', 'POST', { link_id: id }).then(function (res) {
                    if (!res.ok) { alert(errMessage(res)); }
                    loadLinks();
                });
            } else if (act === 'edit') {
                openEditModal(id);
            } else if (act === 'unlink') {
                if (!confirm(I18N.confirmUnlink || 'Remove this link but keep the anchor text?')) { return; }
                btn.disabled = true;
                api('unlink', 'POST', { link_id: id }).then(function (res) {
                    if (!res.ok) { alert(errMessage(res)); }
                    loadLinks();
                });
            } else if (act === 'dismiss') {
                if (!confirm(I18N.confirmDismiss || 'Dismiss this link?')) { return; }
                btn.disabled = true;
                api('dismiss', 'POST', { link_id: id }).then(function (res) {
                    if (!res.ok) { alert(errMessage(res)); }
                    loadLinks();
                });
            }
        });

        loadLinks();
    }

    /* ---- 404 list (markup shell rendered server-side by fourohfour-admin.php). Same pattern as
     * the Broken Links list: pills with counts, Columns menu, selection + Actions/Apply. ---- */
    var fofApp = document.getElementById('lm-fof-app');
    if (fofApp) {
        var fTbody = document.getElementById('lm-fof-tbody');
        var fPager = document.getElementById('lm-fof-pager');
        var fEmpty = document.getElementById('lm-fof-empty');
        var fPage = 1;
        var fPer = 20;
        var fFilter = 'all';
        var fOrderBy = 'human_hits';
        var fOrder = 'DESC';
        var fQuery = '';
        var fSearch = document.getElementById('lm-fof-search');
        var fSearchTimer = null;
        if (fSearch) {
            fSearch.addEventListener('input', function () {
                if (fSearchTimer) { clearTimeout(fSearchTimer); }
                fSearchTimer = setTimeout(function () {
                    fQuery = fSearch.value.trim();
                    fPage = 1;
                    loadFof();
                }, 350);
            });
        }
        var fSelected = {};
        var fSelCount = document.getElementById('lm-fof-selcount');
        var fApply = document.getElementById('lm-fof-apply');
        var fActionInput = document.querySelector('input[name="lm_fof_action"]');
        var fCheckAll = document.getElementById('lm-fof-check-all');

        /* Columns (remembered per browser, own key). */
        var fColsBtn = document.getElementById('lm-fof-cols-btn');
        var fColsPanel = document.getElementById('lm-fof-cols-panel');
        var fCols = {};
        try { fCols = JSON.parse(localStorage.getItem('devdlink_fof_cols') || '{}') || {}; } catch (err) { fCols = {}; }
        function fColOn(k) { return fCols[k] !== false; }
        function fApplyCols(root) {
            (root || fofApp).querySelectorAll('table [data-col]').forEach(function (el) {
                el.classList.toggle('lm-col-hidden', !fColOn(el.getAttribute('data-col')));
            });
        }
        if (fColsBtn && fColsPanel) {
            fColsPanel.querySelectorAll('input[data-col]').forEach(function (cb) {
                cb.checked = fColOn(cb.getAttribute('data-col'));
                cb.addEventListener('change', function () {
                    fCols[cb.getAttribute('data-col')] = cb.checked;
                    try { localStorage.setItem('devdlink_fof_cols', JSON.stringify(fCols)); } catch (err) { /* private mode */ }
                    fApplyCols();
                });
            });
            fColsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = fColsPanel.style.display === 'none';
                fColsPanel.style.display = open ? '' : 'none';
                fColsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function (e) {
                if (!fColsPanel.contains(e.target) && e.target !== fColsBtn) {
                    fColsPanel.style.display = 'none';
                    fColsBtn.setAttribute('aria-expanded', 'false');
                }
            });
            fApplyCols();
        }

        /* Selection + Actions/Apply. */
        function fSelectedIds() {
            return Object.keys(fSelected).filter(function (k) { return fSelected[k]; }).map(function (k) { return parseInt(k, 10) || 0; }).filter(Boolean);
        }
        function fSyncApply() {
            if (!fApply) { return; }
            var act = fActionInput ? fActionInput.value : '';
            fApply.disabled = !act || fSelectedIds().length === 0;
        }
        function fSyncSel() {
            var n = fSelectedIds().length;
            if (fSelCount) { fSelCount.textContent = (I18N.pathsSelected || 'Paths Selected (%d)').replace('%d', String(n)); }
            if (fCheckAll) {
                var boxes = fTbody.querySelectorAll('.lm-row-check');
                fCheckAll.checked = boxes.length > 0 && n === boxes.length;
                fCheckAll.indeterminate = n > 0 && n < boxes.length;
            }
            fSyncApply();
        }
        if (fActionInput) { fActionInput.addEventListener('change', fSyncApply); }
        fTbody.addEventListener('change', function (e) {
            var cb = e.target.closest('.lm-row-check');
            if (!cb) { return; }
            fSelected[cb.getAttribute('data-id')] = cb.checked;
            fSyncSel();
        });
        if (fCheckAll) {
            fCheckAll.addEventListener('change', function () {
                fTbody.querySelectorAll('.lm-row-check').forEach(function (cb) {
                    cb.checked = fCheckAll.checked;
                    fSelected[cb.getAttribute('data-id')] = fCheckAll.checked;
                });
                fSyncSel();
            });
        }
        if (fApply) {
            fApply.addEventListener('click', function () {
                var act = fActionInput ? fActionInput.value : '';
                var ids = fSelectedIds();
                if (!act || !ids.length) { return; }
                if (act === 'delete' && !confirm((I18N.confirmDeleteMany || 'Delete %d 404 entries?').replace('%d', String(ids.length)))) { return; }
                fApply.disabled = true;
                var i = 0;
                (function next() {
                    if (i >= ids.length) { fCache = {}; loadFof(); return; }
                    api('404-action', 'POST', { id: ids[i++], action: act }).then(next);
                })();
            });
        }

        /* Sortable headers: click (or Enter/Space) = sort by that column, click again = flip. */
        var fSortHeads = Array.prototype.slice.call(fofApp.querySelectorAll('th.th-sort[data-sort]'));
        function fSyncSortHeads() {
            fSortHeads.forEach(function (th) {
                var on = th.getAttribute('data-sort') === fOrderBy;
                th.classList.toggle('sorted-desc', on && fOrder === 'DESC');
                th.classList.toggle('sorted-asc', on && fOrder === 'ASC');
                th.setAttribute('aria-sort', on ? (fOrder === 'DESC' ? 'descending' : 'ascending') : 'none');
            });
        }
        fSortHeads.forEach(function (th) {
            var go = function () {
                var key = th.getAttribute('data-sort');
                if (key === fOrderBy) { fOrder = (fOrder === 'DESC') ? 'ASC' : 'DESC'; } else { fOrderBy = key; fOrder = 'DESC'; }
                fPage = 1;
                fSyncSortHeads();
                loadFof();
            };
            th.addEventListener('click', go);
            th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
        });

        /* Pills. */
        document.querySelectorAll('.lm-fof-filter[data-filter]').forEach(function (pill) {
            pill.addEventListener('click', function () {
                document.querySelectorAll('.lm-fof-filter[data-filter]').forEach(function (c) {
                    c.classList.remove('is-on');
                    c.setAttribute('aria-pressed', 'false');
                });
                pill.classList.add('is-on');
                pill.setAttribute('aria-pressed', 'true');
                fFilter = pill.getAttribute('data-filter') || 'all';
                fPage = 1;
                loadFof();
            });
        });
        function fFillCounts(c) {
            document.querySelectorAll('[data-lm-fof-count]').forEach(function (el) {
                var k = el.getAttribute('data-lm-fof-count');
                if (c && c[k] !== undefined) { el.textContent = String(c[k]); }
            });
        }

        var fSeq = 0;
        var fAbort = null;
        var fCache = {};
        var fPainted = false;

        function loadFof() {
            var params = new URLSearchParams();
            params.set('page', fPage);
            params.set('per_page', fPer);
            params.set('humans_only', fFilter === 'humans' ? 1 : 0);
            params.set('show_ignored', 0);
            params.set('ignored_only', fFilter === 'ignored' ? 1 : 0);
            params.set('orderby', fOrderBy);
            params.set('order', fOrder);
            if (fQuery) { params.set('search', fQuery); }
            var key = params.toString();
            if (fCache[key]) {
                renderFof(fCache[key]);
            } else if (!fPainted) {
                loadingRow(fTbody, 4);
            }
            fofApp.classList.add('is-loading');
            if (fAbort) { fAbort.abort(); }
            fAbort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var mySeq = ++fSeq;
            api('404s?' + params.toString(), 'GET', null, fAbort ? fAbort.signal : undefined).then(function (res) {
                if (mySeq !== fSeq || res.aborted) { return; }
                fofApp.classList.remove('is-loading');
                if (!res.ok) {
                    clear(fTbody);
                    var tr = elem('tr');
                    var td = elem('td', 'dd-td');
                    td.colSpan = 4;
                    td.appendChild(elem('span', 'dd-hint', errMessage(res)));
                    tr.appendChild(td);
                    fTbody.appendChild(tr);
                    clear(fPager);
                    return;
                }
                fCache[key] = res.data || {};
                fPainted = true;
                renderFof(fCache[key]);
            });
        }

        function metaLine(col, label, node) {
            var line = elem('span', 'lm-meta');
            line.setAttribute('data-col', col);
            line.appendChild(elem('b', '', label + ':'));
            line.appendChild(node);
            return line;
        }

        function renderFof(data) {
            clear(fTbody);
            fSelected = {};
            if (data.counts) { fFillCounts(data.counts); }
            var items = data.items || [];
            if (!items.length) {
                if (fEmpty) { fEmpty.style.display = 'block'; }
                clear(fPager);
                fSyncSel();
                return;
            }
            if (fEmpty) { fEmpty.style.display = 'none'; }
            items.forEach(function (it) {
                var id = parseInt(it.id, 10) || 0;
                var tr = elem('tr');

                var checkTd = elem('td', 'dd-td lm-col-check');
                var cb = elem('input', 'dd-check lm-row-check');
                cb.type = 'checkbox';
                cb.setAttribute('data-id', String(id));
                cb.setAttribute('aria-label', I18N.selectPath || 'Select this path');
                checkTd.appendChild(cb);
                tr.appendChild(checkTd);

                // Path column, stacked: path + copy, then Referrer / Suggestion / Last seen lines.
                var pathTd = elem('td', 'dd-td');
                pathTd.setAttribute('data-col', 'path');
                var row = elem('div', 'lm-url-row');
                // Resolve against the home URL's ORIGIN: on a subdirectory install the logged
                // path already carries the directory (/blog/missing/), so plain concatenation
                // would double it (/blog/blog/missing/).
                var fullUrl;
                try {
                    fullUrl = new URL(txt(it.path), CFG.home || window.location.origin).href;
                } catch (e) {
                    fullUrl = (CFG.home || '').replace(/\/$/, '') + txt(it.path);
                }
                var pathNode = elem('a', 'lm-url', fullUrl);
                pathNode.href = fullUrl;
                pathNode.target = '_blank';
                pathNode.rel = 'noopener';
                if (it.ignored) { pathNode.appendChild(elem('span', 'lm-code lm-code-muted', I18N.ignored || 'Ignored')); }
                row.appendChild(pathNode);
                row.appendChild(copyButton(fullUrl));
                pathTd.appendChild(row);

                var refNode = it.referrer ? elem('span', 'lm-ref', it.referrer) : elem('span', 'dd-hint', '-');
                pathTd.appendChild(metaLine('referrer', I18N.referrer || 'Referrer', refNode));

                pathTd.appendChild(metaLine('seen', I18N.lastSeen || 'Last seen', document.createTextNode(txt(it.last_seen).substring(0, 16))));
                // Redirect suggestion: computed on request for ONE row (never for the whole page).
                var sugWrap = elem('span', 'lm-suggest');
                var sugBtn = elem('button', 'dd-link-btn lm-suggest-btn', I18N.findRedirect || 'Find redirect');
                sugBtn.type = 'button';
                sugBtn.addEventListener('click', function () {
                    sugBtn.disabled = true;
                    sugBtn.textContent = I18N.finding || 'Looking...';
                    api('404-suggest', 'POST', { id: id }).then(function (res) {
                        clear(sugWrap);
                        if (!res || !res.ok) {
                            // Temporary failure: say why and give the button back, no reload needed.
                            sugWrap.appendChild(elem('span', 'dd-hint', errMessage(res)));
                            sugBtn.disabled = false;
                            sugBtn.textContent = I18N.findRedirect || 'Find redirect';
                            sugWrap.appendChild(sugBtn);
                            return;
                        }
                        var sg = res.data && res.data.suggestion;
                        if (!sg || !sg.url) {
                            sugWrap.appendChild(elem('span', 'dd-hint', I18N.noMatch || 'No close match among published pages.'));
                            return;
                        }
                        var target = elem('a', 'lm-url', sg.label ? (sg.label + ' (' + sg.url + ')') : sg.url);
                        target.href = sg.url;
                        target.target = '_blank';
                        target.rel = 'noopener';
                        sugWrap.appendChild(target);
                        if (sg.htaccess) {
                            var cp = copyButton(sg.htaccess);
                            cp.setAttribute('aria-label', I18N.copyHtaccess || 'Copy .htaccess rule');
                            cp.title = I18N.copyHtaccess || 'Copy .htaccess rule';
                            sugWrap.appendChild(cp);
                        }
                        if (sg.rm_url) {
                            var mk = elem('a', 'dd-link-btn', I18N.createRedirect || 'Create redirect');
                            mk.href = sg.rm_url;
                            sugWrap.appendChild(mk);
                        }
                    });
                });
                sugWrap.appendChild(sugBtn);
                pathTd.appendChild(metaLine('suggest', I18N.redirect || 'Redirect', sugWrap));
                tr.appendChild(pathTd);

                var humanTd = elem('td', 'dd-td');
                humanTd.setAttribute('data-col', 'humans');
                humanTd.appendChild(elem('span', 'lm-num-h', String(parseInt(it.human_hits, 10) || 0)));
                tr.appendChild(humanTd);

                var botTd = elem('td', 'dd-td');
                botTd.setAttribute('data-col', 'bots');
                botTd.appendChild(elem('span', 'lm-num-b', String(parseInt(it.bot_hits, 10) || 0)));
                tr.appendChild(botTd);

                fApplyCols(tr);
                fTbody.appendChild(tr);
            });
            fSyncSel();
            renderPager(fPager, data, fPage, function (target) { fPage = target; loadFof(); }, fPer, function (n) { fPer = n; fPage = 1; loadFof(); });
        }

        loadFof();
    }
})();
