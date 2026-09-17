=== DevDome Link Monitor – Broken Link Checker & 404 Monitor ===
Contributors: devdome
Tags: broken link checker, broken links, link checker, 404 monitor, 404
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Broken link checker for WordPress. Find and fix broken links, detect dead URLs, monitor 404 errors, and reduce false positives.

== Description ==

= Broken Link Checker and 404 Monitor for WordPress =

DevDome Link Monitor is a broken link checker and 404 monitor for WordPress that helps you find and fix broken links, detect dead URLs, and track 404 errors directly from your WordPress dashboard.

Scan the links in your published content, identify genuinely broken URLs, see which missing pages visitors and crawlers are hitting, and fix link problems without opening every post by hand.

Unlike aggressive link scanners, DevDome Link Monitor verifies uncertain failures before it marks a link as broken. That reduces false positives caused by temporary server errors, rate limits, anti-bot protection and connection problems, so the broken link list you get is one you can act on.

= Find Broken Links =

Scan the links in your published WordPress content and see at a glance which URLs are:

* Healthy
* Broken
* Redirected (with the final URL and hop count)
* Unverified (timeouts, DNS failures, TLS errors, refused connections)
* Could not verify (401, 403 after a full GET, 429 rate limited, 999 anti-bot)

The link checker covers internal and external links in one pass, so you do not need to open every URL yourself.

A link is never marked broken because a single request failed. It has to fail twice in checks separated in time, and a link that recovers starts from a clean slate. This keeps temporary errors, rate limiting, anti-bot protection, timeouts and DNS hiccups out of your broken list.

= Fix Broken Links =

Fix broken links directly from the review table in your WordPress dashboard:

* Edit URL: the new address is written into every post that contains that exact link
* Unlink: the link is removed and the anchor text stays
* Re-check now
* Dismiss links you keep on purpose
* Review redirects with their final destination

Every content change goes through WordPress in the normal way, so revisions apply, and it needs your edit permission on every affected post. Each change is recorded in an audit trail.

= Monitor 404 Errors =

The built-in 404 monitor is always on and records every request for a missing URL on your site:

* The requested path, with the query string removed
* Hit counts, first seen and last seen
* The last referrer and, unless you turn it off, the last user agent
* Human hits and bot hits kept apart, using known crawler patterns

Find the URLs visitors are trying to reach but cannot. Each 404 path gets a redirect suggestion matched against your real post and page slugs, a one-click redirect if DevDome Redirect Manager is active, or a copy-ready .htaccess rule. Ignore paths, delete rows, export to CSV, and let old entries purge automatically after 30, 90 or 180 days.

= Link Health at a Glance =

The Overview tab shows a link health score, the number of links checked, healthy links, broken links and redirects, the 404 monitor's human and bot totals, and a health trend over the last 12 scans.

= Lightweight Background Scanning =

Scans run in small batches instead of checking every URL at once, with at most two links per host per batch, so your site stays responsive. Start, pause, resume or cancel a scan at any time. It keeps running in the background while the screen is open or through WP-Cron, and an optional weekly or monthly rescan can email you a summary of newly broken links only.

= Reduce False Positives =

Not every failed automated request means a link is dead. Some sites block automated requests, rate-limit scanners, require authentication, return temporary server errors, reject HEAD requests, or time out now and then. DevDome Link Monitor keeps those uncertain results separate as "Unverified" or "Could not verify" instead of calling them broken, so the broken list stays short and true.

= What Is Scanned, and What Is Not =

Scanned: the saved content (`post_content`) of **published** posts in **public** post types (attachments excluded), meaning `<a href>` and `<img src>`, including markup inside block comments. Internal and external URLs are both checked.

Not scanned: widgets, menus, theme and customizer options, post meta and custom fields, `srcset` candidates, oEmbed and iframe targets, the bodies of reusable or synced patterns that are not inlined in the post, and drafts, private or scheduled content. This is not a whole-site crawler.

= Why Use DevDome Link Monitor? =

* Find broken links in your WordPress content
* Fix broken URLs from the dashboard
* Monitor 404 errors with human and bot hits kept apart
* Detect dead URLs and redirect chains
* Fewer false positives: uncertain links are reviewed separately
* Checks run in small background batches
* Export 404 results to CSV
* No cloud account needed, no page or link limits, everything runs on your own server

= How Link Checking Works =

* Links are discovered in the saved content of published posts, then checked in chunks that are resumable, pausable and cancelable, driven by your browser while the screen is open or by WP-Cron.
* Each link is requested with HEAD first. A size-capped GET (128 KB) is sent when the server rejects or gates HEAD, and before any HEAD failure is recorded, because some servers mishandle HEAD while GET works.
* Redirects are followed manually, up to 5 hops with loop detection, and reported with the final URL and hop count.
* Broken means two failures in checks separated in time (15 seconds by default). Two failures inside the same processing burst do not count as independent evidence.
* Timeouts, DNS failures, TLS errors and refused connections become "Unverified" with the reason. 401, 403 after a full GET, 429 and 999 become "Could not verify". None of these are ever counted as broken.
* Low default budgets and per-host spacing: at most 2 links per host per tick. Check timeout, posts per batch and links per batch are adjustable in Settings.

= AI and Agent Support =

On WordPress 6.9 and newer, DevDome Link Monitor registers WordPress Abilities covering the whole plugin: link health summary, every checked link with its pages and every filter, one link, the 404 log with every filter, redirect suggestions, scan start (full or recheck), pause, resume and cancel, progress and history, recheck one link, replace a link URL in content, unlink, dismiss, ignore or delete a 404, run the retention sweep, exports, settings (read and update), the error and change logs, dismiss the last error and recount the summary. Compatible AI agents and MCP clients can discover and use these abilities when the site exposes them, for example through the official WordPress MCP Adapter. Every ability runs the same code as the plugin screens under the same capability checks; content edits keep the per-post edit permission check.

== External services ==

This plugin makes outbound HTTP requests for one core purpose, one optional DevDome service and one small catalog fetch:

1. **Link checking (the sites you link to): core feature, runs only when a scan runs.** To verify your outgoing links, the plugin sends an HTTP HEAD request (with a GET fallback for servers that reject or gate HEAD) from your server directly to each URL found in your published content. The target server sees your server's IP address, the configurable user-agent string and the URL itself - including anything personal or secret a URL in your own content happens to contain. No cookies, authentication headers or site secrets are sent. A scan only runs when you start one or when the schedule you enabled fires. Before a failure is recorded from a HEAD response, it is confirmed with a size-capped GET, because some servers mishandle HEAD. Before any request is sent the URL must pass a fail-closed policy: http/https only, port 80 or 443 only, no credentials in the URL, and every address the host resolves to must be public - loopback, private, link-local, carrier-grade NAT, multicast and reserved ranges are refused, in every notation, on the first request and on every redirect hop. Link verification requires the PHP cURL transport, because the validated addresses must be pinned to the connection. If cURL is unavailable, or WordPress routes the target through a configured HTTP proxy where the destination cannot be pinned, the URL is left unverified and no request is sent. These are the websites you link to, not DevDome services.
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
* Link checks are performed directly from your WordPress server to the sites you link to. Link scan results (the URLs found in your own content and their HTTP status) are stored in your own database only. **A URL in your content can itself contain personal data or a signed token**; such a URL is sent to its destination server during a check, and may be displayed on the admin screen or exported locally; it is never emailed and never sent to DevDome.
* DevDome receives no scan data unless you connect a DevDome account, and then only the aggregate counts listed under External services. The summary email is sent by DevDome, never by this plugin, and contains counts only (no URLs, no post names). Without a connected DevDome account no email is sent at all.
* The plugin adds suggested text to the WordPress privacy-policy tool describing exactly this.
* Deleting the plugin deletes all of the plugin's own data immediately, on every site of a network: its tables, settings, transients and scheduled events. The bundled shared DevDome library's settings (the account connection, cached detection lists) are removed too when Link Monitor is the last DevDome plugin installed; while other DevDome plugins remain, that shared state is left for them. Deactivating keeps everything.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` and activate it, or install it from the Plugins screen.
2. The 404 monitor starts working immediately - no setup needed.
3. Open **DevDome → Link Monitor** and click **Scan Links** to run your first broken-link scan.

== Frequently Asked Questions ==

= Does it check internal and external links? =
Yes. Every `<a href>` and `<img src>` in the saved content of published posts is checked, whether it points to your own site or to another one. Widgets, menus, custom fields and theme options are not scanned.

= Does it automatically change my content? =
No. Scanning only reads your content and records results. Content changes only when you choose Edit URL or Unlink for a specific link, and only if you have WordPress permission to edit every post that contains it.

= Why is a working link shown as unverified? =
Some websites block automated requests, rate-limit them, require a login, or time out now and then. DevDome Link Monitor keeps those results separate as "Unverified" or "Could not verify" instead of calling the link broken. Use Re-check now, or open the link yourself.

= Why does it report fewer broken links than my old link checker? =
Because it refuses to guess. A link is only marked broken after failing twice in checks separated in time; timeouts, DNS failures and TLS errors are shown as "Unverified" rather than broken; and 401/403/429/999 responses are shown as "Could not verify". What is left is much more likely to be genuinely broken. It is a conservative policy, not a guarantee - a target that answers 200 with a "page not found" page still counts as OK.

= Does the plugin monitor 404 errors? =
Yes, always. Every request for a missing URL is recorded with its path, hit count, last referrer, last user agent and first and last seen time, with human hits and bot hits kept apart.

= Will scanning slow down my website? =
404 logging adds one indexed lookup and one write, and only on requests that are already 404s. The scan runs in small chunks with low default budgets and at most two links per host per tick, but it does run on your server and does use PHP workers, so a scan is not free. Lower the batch sizes in Settings on small hosts.

= How does the bot/human split work? =
It matches the user-agent string against known crawler patterns. That is all it can do: user agents are trivially forged, so treat "human" as "not obviously a crawler". Requests with no user agent are counted as bots. The Settings tab names the classifier that produced the split.

= Do scheduled scans work on a quiet site? =
They use WP-Cron, which only fires when someone visits your site (or when a real cron job calls `wp-cron.php`). On a low-traffic site a scheduled scan can run late.

= Does DevDome receive my link data? =
No. Link checks go from your server straight to the sites you link to. Nothing is sent to DevDome unless you connect a DevDome account, and even then only aggregate counts (links checked, healthy, broken, redirects) for the optional email summary. Never URLs, anchor text, post titles or 404 paths.

= Does it support multisite? =
Yes. Network activation provisions the sites it can reach in one request; any remaining site (and any site created later while the plugin is network-active) creates its own tables on its first load. Settings, the 404 log and scan results are per site.

== Screenshots ==

1. Link Health Overview: links checked, healthy links, broken links, redirects, 404 activity with human and bot hits, and the overall link health score.
2. Broken Link Checker: find broken, redirected and unverified links in your WordPress content, with filters and the fix actions (edit URL, unlink, re-check, dismiss).
3. 404 Monitor: track missing URLs, hit counts, referrers and whether the requests came from humans or bots.
4. Scan Settings: check timeout, posts and links per batch, user agent and excluded domains.

== Changelog ==

= 1.7.2 =
* Updates now work when the plugin folder belongs to another system user (shared DevDome core 1.7.4): a folder installed from a root shell or by an AI agent used to fail every update with "Could not move the old version", and an uploaded zip kept the old version. The plugin is copied to a web-owned folder right before WordPress replaces it, the old folder is kept hidden and recorded so the DevDome Malware Scanner recognises it, and the update goes through the hub, the Plugins screen, bulk updates, uploads and automatic updates alike.

= 1.7.1 =
* Connect fix (shared DevDome core 1.6.6): the connect claim now waits up to 30 seconds and keeps the handshake for 20 minutes so a refresh retries it, the DevDome hub shows why a connect failed with a Try again link, and the verify file is served through a query form for hosts that answer /.well-known/ before WordPress.

= 1.7.0 =
* WordPress Abilities API: 23 abilities covering every feature (links, 404s, scans, edit URL, unlink, dismiss, 404 ignore and delete, purge, exports, settings, logs). Empty-input abilities refuse unexpected arguments cleanly.

= 1.6.0 =
* WordPress Abilities API support (WordPress 6.9+): six abilities for AI agents and MCP clients: get-link-summary, get-broken-links, get-404s, get-scan-progress, run-link-scan, recheck-link. Same code and capability checks as the plugin screens.

= 1.5.4 =
* Settings: every option now shows a one line hint under the control, with the info icon holding the full explanation, the same layout as DevDome Malware Scanner.
* DevDome Dashboard: installing another DevDome plugin from the dashboard no longer activates it, you activate it yourself from its card. Output escaping tightened.

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
