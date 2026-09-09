=== DevDome Link Monitor ===
Contributors: devdome
Tags: broken links, 404, broken link checker, redirect, link checker
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken links and monitor 404s, with a conservative checker that says "unverified" instead of guessing.

== Description ==

DevDome Link Monitor is two tools in one: a passive 404 monitor that logs every missing-page hit as it happens, and an on-demand broken link scanner that checks the links in your published content in small background slices. Both are built around the two complaints site owners have about the incumbents: links wrongly called broken, and scanners that hammer the server.

**Tool 1 — 404 Monitor (always on)**

* Every 404 is logged automatically: normalized path (query string removed), hit counters, last referrer, last user agent, first/last seen.
* **Bot/human split from the user-agent string.** Each hit is matched against known crawler patterns, so obvious crawler noise is separated from everything else. A user agent can be forged, so "human" means "did not match a known crawler pattern" - it is not proof of a real visitor. A request with no user agent at all is always counted as a bot. The screen names the classifier it used.
* Humans-only view, per-path ignore, row delete, CSV export, and automatic purging (30/90/180 days, your choice) with a row cap. Ignored paths have their own bounds: they are kept for one year and capped in number, so they cannot grow without limit either.
* Redirect suggestions: each 404 path is fuzzy-matched against your published post and page slugs. If DevDome Redirect Manager is active you get a one-click "Create redirect" prefill; otherwise a copy-ready .htaccess rule.

**Tool 2 — Broken Link Scanner (on demand)**

* Chunked, resumable, pausable and cancelable. It runs in small background slices driven by your browser while the screen is open, or by WP-Cron.
* **Conservative by design.** A link is only marked broken after it fails twice in checks that are separated in time (15 seconds by default) - two failures inside the same processing burst are not treated as independent evidence, and a link that recovers starts from a clean slate. Timeouts, DNS failures, TLS errors and refused connections are never counted as broken; they are shown as "Unverified" with the reason. 401 (authentication required), 403 after a full GET, 429 (rate limited) and 999 (anti-bot) are shown as "Could not verify", never as broken.
* Redirect chains are followed manually (up to 5 hops, loop detection) and reported with the final URL and hop count.
* Fix problems from the review table: Re-check now, Edit URL (updates every post that contains that exact link), Unlink (keeps the anchor text), or Dismiss. Every content change requires WordPress' own permission to edit each affected post and is recorded in an audit trail.
* Low default budgets and per-host courtesy spacing: at most 2 links per host are processed per tick. Verifying one link can involve more than one request - HEAD is used first, a size-capped GET (128 KB) is sent when the server rejects or gates HEAD or before any HEAD failure is recorded (some servers mishandle HEAD while GET works), and redirects are followed up to 5 hops.
* Optional weekly/monthly scheduled rescan with an email summary of NEW broken links only (sent to the email of your connected DevDome account).

= What is scanned, and what is not =

Scanned: the saved content (`post_content`) of **published** posts in **public** post types (attachments excluded) - `<a href>` and `<img src>`, including markup inside block comments.

Not scanned: widgets, menus, theme and customizer options, post meta and custom fields, `srcset` candidates, oEmbed/iframe targets, the bodies of reusable/synced patterns that are not inlined in the post, and drafts, private or scheduled content. This is not a whole-site crawler.

**Link health at a glance**

The Overview tab shows a link-health score, checked/healthy/broken/redirect counts, and the 404 monitor's human-vs-bot totals.

== External services ==

This plugin makes outbound HTTP requests for one core purpose, one optional DevDome service and one small catalog fetch:

1. **Link checking (the sites you link to): core feature, runs only when a scan runs.** To verify your outgoing links, the plugin sends an HTTP HEAD request (with a GET fallback for servers that reject or gate HEAD) from your server directly to each URL found in your published content. The target server sees your server's IP address, the configurable user-agent string and the URL itself - including anything personal or secret a URL in your own content happens to contain. No cookies, authentication headers or site secrets are sent. A scan only runs when you start one or when the schedule you enabled fires. Before a failure is recorded from a HEAD response, it is confirmed with a size-capped GET, because some servers mishandle HEAD. Before any request is sent the URL must pass a fail-closed policy: http/https only, port 80 or 443 only, no credentials in the URL, and every address the host resolves to must be public - loopback, private, link-local, carrier-grade NAT, multicast and reserved ranges are refused, in every notation, on the first request and on every redirect hop. Link verification requires the PHP cURL transport, because the validated addresses must be pinned to the connection. If cURL is unavailable, or WordPress routes the target through a configured HTTP proxy where the destination cannot be pinned, the URL is left unverified and no request is sent.
2. **DevDome account service (analytics.devdome.com and api.devdome.com): optional, opt-in, dormant.** The Overview tab shows an optional card that links to the bundled DevDome suite hub's Account screen. Nothing is sent to DevDome until you click Connect there, and no functionality on this screen is gated on connecting. If you do connect: the plugin sends your site address, a generated site ID and a generated secret site token to `analytics.devdome.com/api/plugin/connect/start` and `/api/plugin/connect/claim` to link this site to your account; afterwards it periodically confirms the connection at `api.devdome.com/plugin/account` (sending the site domain and that token), and disconnecting sends the same identifiers to `api.devdome.com/plugin/disconnect`. No scanned URL, anchor text, post title, 404 path, referrer, user agent or visitor IP address is sent by this plugin. Terms: https://devdome.com/terms-of-service and privacy: https://devdome.com/privacy-policy
   Once connected, each completed link scan sends DevDome this site's DevDome identifiers (the site ID, and the site token in a request header), the scan number, aggregate scan metrics (links checked, healthy, broken, redirects, health score, how many links are newly broken), whether the email summary is switched on, and the address of this plugin screen (so the email can link back to it). It does not send scanned link URLs, anchor text, source post titles, 404 request paths, referrers, user agents or visitor IP addresses. DevDome uses it for your account dashboard and to email you that summary from alerts@devdome.com. Nothing is sent while the site is not connected.
3. **Plugin catalog (`devdome.com`).** The DevDome Dashboard inside wp-admin fetches the list of DevDome plugins (names, descriptions, logos, links, WordPress.org slugs) from `https://devdome.com/wp-plugins/catalog.json` at most once every 12 hours, so the list stays current. Only the bundled core version is sent in the request; no site or visitor data. Service provider: DevDome. Terms: https://devdome.com/terms-of-service Privacy policy: https://devdome.com/privacy-policy

= Dormant endpoints in the bundled DevDome core =

The bundled shared library also references endpoints that are never contacted by default on this WordPress.org build:

* `https://api.devdome.com/bot-protection/list`, `https://api.devdome.com/bot-protection/asns` and `https://api.devdome.com/bot-protection/drop`: the shared library's detection-list sync. On this WordPress.org build these endpoints are NEVER contacted - the sync is disabled in code, connected or not. The 404 monitor's bot/human split always works from the plugin's built-in user-agent patterns.

== Privacy ==

* The 404 monitor stores, in your own database only: the requested path with the query string removed, hit counts, the last referrer and (unless you turn it off) the last user agent per path. **No IP addresses are stored and no cookie is set.**
* **Referrer query strings are never stored.** A referrer is reduced to `scheme://host/path` by default; you can reduce it further to the origin only, or turn referrer storage off completely. Parameters that look like credentials or personal data, and any bare email address, are masked before the value is written, and again before it is shown or exported.
* Log retention is automatic (30/90/180 days for active rows, one year for ignored rows) with row caps on both.
* Link scan results (the URLs found in your own content and their HTTP status) are stored in your own database only. **A URL in your content can itself contain personal data or a signed token**; such a URL is sent to its destination server during a check, and may be displayed on the admin screen or exported locally; it is never emailed and never sent to DevDome.
* The summary email is sent by DevDome, never by this plugin, and contains counts only (no URLs, no post names). Without a connected DevDome account no email is sent at all.
* The plugin adds suggested text to the WordPress privacy-policy tool describing exactly this.
* Deleting the plugin deletes all of the plugin's own data immediately, on every site of a network: its tables, settings, transients and scheduled events. The bundled shared DevDome library's settings (the account connection, cached detection lists) are removed too when Link Monitor is the last DevDome plugin installed; while other DevDome plugins remain, that shared state is left for them. Deactivating keeps everything.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it, or install it from the Plugins screen.
2. The 404 monitor starts working immediately - no setup needed.
3. Open **DevDome Tools → Link Monitor** and click **Scan Links** to run your first broken-link scan.

== Frequently Asked Questions ==

= Why does it report fewer broken links than my old link checker? =
Because it refuses to guess. A link is only marked broken after failing twice in checks separated in time; timeouts, DNS failures and TLS errors are shown as "Unverified" rather than broken; and 401/403/429/999 responses are shown as "Could not verify". What is left is much more likely to be genuinely broken. It is a conservative policy, not a guarantee - a target that answers 200 with a "page not found" page still counts as OK.

= Does it slow down my site? =
404 logging adds one indexed lookup and one write, and only on requests that are already 404s. The scan runs in small chunks with low default budgets and at most two links per host per tick, but it does run on your server and does use PHP workers, so a scan is not free. Lower the batch sizes in Settings on small hosts.

= How does the bot/human split work? =
It matches the user-agent string against known crawler patterns. That is all it can do: user agents are trivially forged, so treat "human" as "not obviously a crawler". Requests with no user agent are counted as bots. The Settings tab names the classifier that produced the split.

= Do scheduled scans work on a quiet site? =
They use WP-Cron, which only fires when someone visits your site (or when a real cron job calls `wp-cron.php`). On a low-traffic site a scheduled scan can run late.

= Can it fix links, not just find them? =
Yes. From the review table you can edit a URL (updated in every post that contains it), unlink it while keeping the anchor text, re-check it, or dismiss it. You must have WordPress permission to edit **every** post the link appears in - if even one is out of reach, nothing is changed. Every change is written to WordPress in the normal way (so revisions apply) and recorded in an audit trail.

= Does it support multisite? =
Yes. Network activation provisions the sites it can reach in one request; any remaining site (and any site created later while the plugin is network-active) creates its own tables on its first load. Settings, the 404 log and scan results are per site.

== Changelog ==

= 1.5.3 =
* Shared DevDome library updated to core 1.6.3: the DevDome Dashboard now reads the current plugin catalog (names, descriptions, logos, links) from devdome.com once every 12 hours, and other DevDome plugins can be installed from WordPress.org in one click.

= 1.5.2 =
* First tab is now called Overview, in line with the other DevDome plugins. Old links to the Dashboard tab still open it.

= 1.5.1 =
* Scan history pruning now binds every id in its IN (...) lists through $wpdb->prepare().

= 1.5.0 =
* First WordPress.org release.
* Broken link scanner with a two-strike rule: a link is only called broken after two separate failures, and 401, 403, 429 and anti-bot answers are reported as blocked, never broken.
* Passive 404 monitor with human hits and bot hits kept apart, redirect suggestions on request, .htaccess rule copy and Redirect Manager hand-off, CSV export.
* Edit, unlink or dismiss a link from the list; every content change is verified against the saved post and needs edit rights on that post.
* Scans run in bounded chunks and finish on their own, with a closed browser and without visitors, and recover from an interrupted finish.
* Optional email summary of newly broken links, sent by DevDome to the connected account email, counts only.
* Outbound checks refuse private and reserved addresses and pin the resolved address to the connection; requires the cURL extension.

