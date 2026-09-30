=== DevDome Link Monitor: Broken Link Checker, Dead Link Checker, Broken Link Finder ===
Contributors: devdome
Tags: broken link checker, dead link checker, external link checker, broken link finder, 404 monitor
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken links, broken images, and dead links in published content. Edit exact URLs in place and log visitor or bot 404 errors in the 404 monitor.

== Description ==

DevDome Link Monitor is a broken link checker and dead link checker for WordPress. Find broken links, dead links and broken images in the saved content of published posts in public post types, excluding attachments.

Use the broken link finder as an internal and external link checker. Edit an exact link URL across the posts containing it, or remove the link while keeping its anchor text. Link checks run on your server, with no cloud account or page or link limits.

The built-in 404 monitor logs missing-page requests from visitors and bots, with separate human and bot hit counts. Review 404 errors, ignore paths, delete entries and export the log to CSV.

= Find broken links and missing images =

Use the broken link finder to check internal links and external links in one scan. It also checks image URLs in saved content to help you find broken images and missing images.

The review table separates results into:

* Healthy.
* Broken.
* Redirected, with the final URL and hop count.
* Unverified, with the reason: timeouts, DNS failures, TLS errors or refused connections.
* Could not verify: 401, 403 after a full GET, 429 rate limited or 999 anti-bot responses.

As a dead link checker, the plugin requires two failures in checks separated in time before marking a URL broken. A link that recovers starts from a clean slate.

= What the broken link scanner checks =

The scanner reads saved content (`post_content`) from **published** posts in **public** post types, excluding attachments. It checks `<a href>` and `<img src>` URLs, including markup inside block comments.

These sources are not scanned:

* Widgets, menus, theme options and customizer options.
* Post meta and custom fields.
* `srcset` candidates, oEmbed targets and iframe targets.
* Bodies of reusable or synced patterns that are not inlined in the post.
* Drafts, private content and scheduled content.

This is not a whole-site crawler. During a content audit, use its results to review links in the published content covered above.

= Replace links from the dashboard =

The review table provides these actions:

* Edit URL: write the new address into every post containing that exact link.
* Unlink: remove the link and keep its anchor text.
* Re-check now: check the URL again.
* Dismiss: set aside links you keep on purpose.
* Review redirects and their final destinations.

The search replace scope is limited to exact link URLs through Edit URL. It does not replace arbitrary text or other database values.

Content changes go through WordPress normally, so revisions apply. You need edit permission on every affected post, and each change is recorded in an audit trail.

= Monitor 404 not found requests =

The built-in 404 monitor is always on. It records requests for missing URLs on your site, including requests that reach a 404 page:

* Requested path, with the query string removed.
* Hit count, first seen and last seen.
* Last referrer and, unless disabled, last user agent.
* Separate human and bot hit counts, based on known crawler patterns.

Use the log to see which missing URLs visitors and crawlers keep requesting. You can ignore paths, delete rows and export results to CSV. Old active entries purge automatically after 30, 90 or 180 days.

= Review 404 redirect suggestions =

Each 404 path can receive a redirect suggestion matched against your real post and page slugs. To redirect 404 requests, use the one-click action when DevDome Redirect Manager is active, or copy the suggested .htaccess rule.

= Review link health =

The Overview tab shows:

* Link health score.
* Number of links checked, healthy links, broken links and redirects.
* Human and bot totals from the 404 monitor.
* Health trend over the last 12 scans.

Use these results alongside your other SEO tools for the broken-link portion of an SEO audit or site audit. The score describes link health within the plugin's scan scope, not a complete assessment of your website.

= Run scans in the background =

Start, pause, resume or cancel a scan at any time. Scans run in small, resumable batches, driven by your browser while the screen is open or by WP-Cron.

Low default budgets and per-host spacing limit each batch or tick to at most two links per host. Settings lets you adjust check timeout, posts per batch and links per batch, and configure the user agent and excluded domains.

Optional weekly or monthly rescans can produce an email summary of newly broken links only. Email requires a connected DevDome account and is sent by DevDome, with counts only.

= How verification reduces false positives =

Sites may reject HEAD requests, block automated requests, require authentication, rate-limit scanners, return temporary server errors or time out. These responses need different treatment from a confirmed broken URL.

Verification follows these rules:

* Request each URL with HEAD first.
* Send a size-capped GET of 128 KB when a server rejects or gates HEAD, and before recording any HEAD failure. Some servers mishandle HEAD while GET works.
* Follow redirects manually, up to 5 hops, with loop detection. Report the final URL and hop count.
* Require two failures separated in time, 15 seconds by default. Two failures within the same processing burst do not count as independent evidence.
* Keep timeouts, DNS failures, TLS errors and refused connections as "Unverified".
* Keep 401, 403 after a full GET, 429 and 999 responses as "Could not verify".

Unverified and Could not verify results never count as broken. This reduces false positives from temporary failures and access restrictions, but cannot identify every problem: a server returning HTTP 200 for a "page not found" page still counts as OK.

= AI and agent support =

On WordPress 6.9 and newer, the plugin registers WordPress Abilities covering the whole plugin:

* Read the link health summary, every checked link with its pages and every filter, or one link.
* Read the 404 log with every filter and get redirect suggestions.
* Start a full scan or recheck, pause, resume, cancel, and read progress and history.
* Recheck one link, replace a link URL in content, unlink or dismiss.
* Ignore or delete a 404, run the retention sweep and export data.
* Read and update settings, read error and change logs, dismiss the last error and recount the summary.

Compatible AI agents and MCP clients can discover and use these abilities when the site exposes them, for example through the official WordPress MCP Adapter.

Every ability runs the same code as the plugin screens under the same capability checks. Content edits retain the per-post edit permission check.

== External services ==

This plugin makes outbound HTTP requests for one core purpose, one optional DevDome service and one small catalog fetch:

1. **Link checking (the sites you link to): core feature, runs only when a scan runs.** To verify your outgoing links, the plugin sends an HTTP HEAD request (with a GET fallback for servers that reject or gate HEAD) from your server directly to each URL found in your published content. The target server sees your server's IP address, the configurable user-agent string and the URL itself - including anything personal or secret a URL in your own content happens to contain. No cookies, authentication headers or site secrets are sent. A scan only runs when you start one or when the schedule you enabled fires. Before a failure is recorded from a HEAD response, it is confirmed with a size-capped GET, because some servers mishandle HEAD. Before any request is sent the URL must pass a fail-closed policy: http/https only, port 80 or 443 only, no credentials in the URL, and every address the host resolves to must be public - loopback, private, link-local, carrier-grade NAT, multicast and reserved ranges are refused, in every notation, on the first request and on every redirect hop. Link verification requires the PHP cURL transport, because the validated addresses must be pinned to the connection. If cURL is unavailable, or WordPress routes the target through a configured HTTP proxy where the destination cannot be pinned, the URL is left unverified and no request is sent. These are the websites you link to, not DevDome services.
2. **DevDome account service (analytics.devdome.com and api.devdome.com): optional, opt-in, dormant.** The Settings tab shows the DevDome Account card, which states everything below before you press Connect; the Overview status button leads to it. Nothing is sent to DevDome until you click Connect there, and no functionality on this screen is gated on connecting. If you do connect: the plugin sends your site address, a generated site ID and a generated secret site token to `analytics.devdome.com/api/plugin/connect/start` and `/api/plugin/connect/claim` to link this site to your account; afterwards it periodically confirms the connection at `api.devdome.com/plugin/account` (sending the site domain and that token), and disconnecting sends the same identifiers to `api.devdome.com/plugin/disconnect`. No scanned URL, anchor text, post title, 404 path, referrer, user agent or visitor IP address is sent by this plugin. When you connect from the DevDome Tools dashboard or from this plugin's Settings account card, both of which state this before you press the button, those account checks also carry the slug and version of each active DevDome plugin on the site plus the bundled DevDome library, WordPress and PHP versions, so your DevDome account can show your sites and their DevDome plugins for support and update notices. Nothing about other plugins, users, email addresses, content or visitors is included. Sites connected before this was introduced, and sites connected from a button that does not show that text, do not send the list. Disconnecting stops the plugin list. Terms: https://devdome.com/terms-of-service and privacy: https://devdome.com/privacy-policy
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

Yes. Every `<a href>` and `<img src>` in the saved content of published posts is checked, whether it points to your own site or another site. Widgets, menus, custom fields and theme options are not scanned. The full scan scope and exclusions are listed in the Description.

= Does it automatically change my content? =

No. Scanning only reads your content and records results. Content changes only when you choose Edit URL or Unlink for a specific link, and only if you have WordPress permission to edit every post that contains it.

= Why is a working link shown as unverified? =

Some websites block automated requests, rate-limit them, require a login or time out. DevDome Link Monitor keeps those results separate as "Unverified" or "Could not verify" instead of calling the link broken. Use Re-check now, or open the link yourself.

= Why does it report fewer broken links than my old link checker? =

A link is only marked broken after failing twice in checks separated in time. Timeouts, DNS failures and TLS errors are shown as "Unverified"; 401/403/429/999 responses are shown as "Could not verify".

This conservative policy makes the remaining results more likely to be broken, but it is not a guarantee. A target that answers 200 with a "page not found" page still counts as OK.

= Does the plugin monitor 404 errors? =

Yes, always. Every request for a missing URL is recorded with its path, hit count, first and last seen time, and the last referrer and user agent according to your privacy settings. Human hits and bot hits are kept apart.

As a 404 checker, the link scan also checks URLs found in published content. The always-on log records incoming missing-page requests separately.

= Will scanning slow down my website? =

404 logging adds one indexed lookup and one write, and only on requests that are already 404s. Scans run in small chunks with low default budgets and at most two links per host per tick.

Scanning runs on your server and uses PHP workers, so it is not free. Lower the batch sizes in Settings on small hosts.

= How does the bot/human split work? =

It matches the user-agent string against known crawler patterns. User agents are easily forged, so treat "human" as "not obviously a crawler". Requests with no user agent are counted as bots. The Settings tab names the classifier that produced the split.

= Do scheduled scans work on a quiet site? =

They use WP-Cron, which only fires when someone visits your site or when a real cron job calls `wp-cron.php`. On a low-traffic site a scheduled scan can run late.

= Does DevDome receive my link data? =

No link URLs are sent to DevDome. Link checks go from your server straight to the sites you link to.

No scan data is sent to DevDome unless you connect a DevDome account. Once connected, DevDome receives the aggregate scan counts and associated account and scan metadata listed under External services, for the account dashboard and optional email summary. Never URLs, anchor text, post titles or 404 paths.

= Does it support multisite? =

Yes. Network activation provisions the sites it can reach in one request. Any remaining site, and any site created later while the plugin is network-active, creates its own tables on its first load. Settings, the 404 log and scan results are per site.

= How do I find links not working in WordPress? =

Open **DevDome → Link Monitor**, click **Scan Links** and review the broken results. Use Edit URL to replace an exact link across its source posts, or Unlink to keep the anchor text without the link. Check uncertain results separately with Re-check now or by opening the URL yourself.

= Can I redirect 404 errors from the log? =

You can request suggestions matched against existing post and page slugs. Use the one-click redirect action when DevDome Redirect Manager is active, or copy the .htaccess rule for the path.

== Screenshots ==

1. Link Health Overview: links checked, healthy links, broken links, redirects, 404 activity with human and bot hits, and the overall link health score.
2. Broken Link Checker: find broken, redirected and unverified links in your WordPress content, with filters and the fix actions (edit URL, unlink, re-check, dismiss).
3. 404 Monitor: track missing URLs, hit counts, referrers and whether the requests came from humans or bots.
4. Scan Settings: check timeout, posts and links per batch, user agent and excluded domains.

== Changelog ==

= 1.7.4 =

* Listing text updated: title, short description, tags and introduction.
* Settings: the DevDome Account card, the same as in the other DevDome plugins, explains what connecting sends before you press Connect; the Overview button now leads to it.
* Shared DevDome library 1.7.8: the one-time Report a bug hint is recorded through a nonce-checked request instead of on a page view.
* Shared DevDome library 1.7.9: the DevDome dashboard lists only real problems (a feature that is off, paused or not connected is no longer an issue) and no longer says Not monitored.

= 1.7.3 =
* Bundled DevDome library 1.7.6: if you connect a DevDome account from the DevDome Tools dashboard, the Connect card now says exactly what is shared, including the list of active DevDome plugins and their versions. Sites that were already connected, and sites that never connect, send nothing new. See External services.
* Listing text rewritten: new title, short description, tags and a restructured description. No change to how the plugin works.

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
