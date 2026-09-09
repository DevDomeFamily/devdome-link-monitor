<?php
/**
 * HTTP link checker — deliberately conservative about the word "broken".
 *
 * HEAD with a GET fallback (405/403), redirects followed MANUALLY (redirection => 0, max 5
 * hops, loop detection), per-host courtesy spacing (at most 2 URLs per host per tick).
 * Classification is deliberately conservative:
 *   - a 4xx/5xx failure is only 'suspect' on first strike; it becomes 'broken' ONLY after a
 *     second failure in a separate, later tick (the recheck phase),
 *   - timeouts NEVER count as broken,
 *   - 999 / persistent-403 anti-bot responses are 'blocked' (honest label), not broken.
 */

defined('ABSPATH') || exit;

/**
 * Check one batch of links for a scan, capped at 2 URLs per host per call (courtesy
 * spacing — the rest of that host waits for the next tick).
 *
 * @param int    $scan_id
 * @param string $from_status pending | suspect | flagged (flagged = broken/timeout/blocked
 *                            rows not yet re-checked since $cutoff — used by recheck jobs so
 *                            row statuses are never destructively reset up front)
 * @param int    $limit       max links this call
 * @param string $cutoff      MySQL datetime (local time); only used with 'flagged'
 * @return array{checked:int, remaining:int}
 */
function devdlink_check_batch($scan_id, $from_status, $limit, $cutoff = '')
{
    global $wpdb;
    $scan_id = (int) $scan_id;
    $from_status = in_array($from_status, array('pending', 'suspect', 'flagged'), true) ? $from_status : 'pending';
    $phase = ($from_status === 'pending') ? 'check' : 'recheck';
    $limit = max(1, min(50, (int) $limit));

    $table = $wpdb->prefix . 'devdlink_links';
        $wait = 0;
    devdlink_db_reset_error();
    // Fetch a wider window ordered by host so the in-PHP 2-per-host cap can still fill the
    // batch from other hosts. Checked rows change status (or checked_at, for 'flagged'), so
    // no cursor is needed.
    if ($from_status === 'flagged') {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table batch pick; values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE scan_id = %d AND status IN ('broken', 'timeout', 'blocked')
             AND (checked_at IS NULL OR checked_at < %s) ORDER BY host ASC, id ASC LIMIT %d",
            $scan_id,
            $cutoff,
            $limit * 5
        ));
    } elseif ($from_status === 'suspect') {
        // Strike two must be an INDEPENDENT observation: only suspects whose first failure is
        // at least the configured gap old are eligible. Without this the "two strikes" rule was
        // two processing ticks in the same second, so one transient 500 meant 'broken'.
        $eligible_before = devdlink_mysql_time_ago(devdlink_strike_gap());
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table batch pick; values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE scan_id = %d AND status = 'suspect'
             AND checked_at IS NOT NULL AND checked_at <= %s ORDER BY host ASC, id ASC LIMIT %d",
            $scan_id,
            $eligible_before,
            $limit * 5
        ));
        if (!$rows) {
            // Nothing eligible yet — tell the caller how long to wait instead of spinning.
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table read; scan id bound via prepare.
            $oldest = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT MIN(checked_at) FROM {$table} WHERE scan_id = %d AND status = 'suspect'",
                $scan_id
            ));
            if ($oldest !== '') {
                $wait = (int) (strtotime($oldest) + devdlink_strike_gap() - strtotime(current_time('mysql')));
                $wait = max(0, $wait);
            }
        }
    } else {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table batch pick; values bound via prepare.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE scan_id = %d AND status = %s ORDER BY host ASC, id ASC LIMIT %d",
            $scan_id,
            $from_status,
            $limit * 5
        ));
    }

    if (devdlink_db_failed()) {
        return array('checked' => 0, 'remaining' => -1, 'wait' => 0, 'error' => 'db_read');
    }
    $checked = 0;
    $started = time();
    if ($rows) {
        $per_host = array();
        foreach ($rows as $row) {
            if ($checked >= $limit) {
                break;
            }
            // Wall-clock bound: stay far inside the 600s tick lock TTL / job self-heal window
            // even with worst-case timeouts. The remaining count below is recounted, so the
            // phase machine simply continues on the next tick.
            if ($checked > 0 && (time() - $started) > 45) {
                break;
            }
            $host = (string) $row->host;
            if (!isset($per_host[$host])) {
                $per_host[$host] = 0;
            }
            if ($per_host[$host] >= 2) {
                continue; // courtesy spacing: this host waits for the next tick.
            }
            $per_host[$host]++;

            $res = devdlink_check_url((string) $row->url);
            if (devdlink_apply_result($row, $res, $phase) === false) {
                // The database refused the result write: report it as a database error so the
                // queue backs off (5s, error recorded) instead of re-requesting the same URL
                // every tick while MySQL stays unable to save the result.
                return array('checked' => $checked, 'remaining' => -1, 'wait' => 0, 'error' => 'db_write');
            }
            $checked++;
        }
    }

        devdlink_db_reset_error();
    if ($from_status === 'flagged') {
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scan_id = %d AND status IN ('broken', 'timeout', 'blocked')
             AND (checked_at IS NULL OR checked_at < %s)",
            $scan_id,
            $cutoff
        ));
    } else {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table count; values bound via prepare.
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE scan_id = %d AND status = %s",
            $scan_id,
            $from_status
        ));
    }

        if (devdlink_db_failed()) {
        return array('checked' => $checked, 'remaining' => -1, 'wait' => 0, 'error' => 'db_read');
    }
    return array('checked' => $checked, 'remaining' => $remaining, 'wait' => $wait);
}

/**
 * Minimum separation, in seconds, between the two failing observations that make a link
 * 'broken'. Clamped so it can never be turned into "two ticks in the same second" again.
 *
 * @return int
 */
function devdlink_strike_gap()
{
    // 15s default / 5s floor (was 300 / 60): a scan idled 1-5 minutes doing nothing between
    // strike one and strike two. Two observations seconds apart still rule out one flaky
    // response; a real outage keeps failing either way.
    return max(5, min(DAY_IN_SECONDS, devdlink_get_int('strike_gap_seconds', 15)));
}

/**
 * MySQL local datetime $seconds in the past (the same clock current_time('mysql') writes).
 *
 * @param int $seconds
 * @return string
 */
function devdlink_mysql_time_ago($seconds)
{
    // WordPress pins PHP's clock to UTC, so gmdate() is the exact inverse of strtotime() here
    // and the value round-trips against the same clock current_time('mysql') writes.
    return gmdate('Y-m-d H:i:s', strtotime(current_time('mysql')) - max(0, (int) $seconds));
}

/**
 * Is this IPv4 literal a public (globally routable) address? Every IANA special-purpose
 * block is rejected explicitly — filter_var's NO_PRIV/NO_RES coverage varies by PHP build,
 * so it is not used as the boundary.
 *
 * @param string $ip dotted-quad IPv4
 * @return bool
 */
function devdlink_ipv4_is_public($ip)
{
    $n = ip2long((string) $ip);
    if ($n === false) {
        return false;
    }
    $n = (int) sprintf('%u', $n);
    $blocks = array(
        array('0.0.0.0', 8),          // "this network"
        array('10.0.0.0', 8),         // private
        array('100.64.0.0', 10),      // carrier-grade NAT
        array('127.0.0.0', 8),        // loopback
        array('169.254.0.0', 16),     // link-local (cloud metadata 169.254.169.254)
        array('172.16.0.0', 12),      // private
        array('192.0.0.0', 24),       // IETF protocol assignments
        array('192.0.2.0', 24),       // TEST-NET-1
        array('192.88.99.0', 24),     // 6to4 relay anycast
        array('192.168.0.0', 16),     // private
        array('198.18.0.0', 15),      // benchmarking
        array('198.51.100.0', 24),    // TEST-NET-2
        array('203.0.113.0', 24),     // TEST-NET-3
        array('224.0.0.0', 4),        // multicast
        array('240.0.0.0', 4),        // reserved + 255.255.255.255
    );
    foreach ($blocks as $b) {
        $base = (int) sprintf('%u', ip2long($b[0]));
        $mask = (0xFFFFFFFF << (32 - $b[1])) & 0xFFFFFFFF;
        if (($n & $mask) === ($base & $mask)) {
            return false;
        }
    }
    return true;
}

/**
 * Is this IPv6 literal a public address? IPv4-mapped/compatible forms are classified by
 * their embedded IPv4 (::ffff:127.0.0.1 is loopback), and every form that tunnels or
 * embeds an IPv4 address is rejected outright.
 *
 * @param string $ip
 * @return bool
 */
function devdlink_ipv6_is_public($ip)
{
    $packed = @inet_pton((string) $ip); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- inet_pton warns on malformed input; false is handled.
    if ($packed === false || strlen($packed) !== 16) {
        return false;
    }
    $hex = bin2hex($packed);

    // ::/96 and ::ffff:0:0/96 — classify the embedded IPv4 (covers ::, ::1, ::ffff:127.0.0.1).
    if (strpos($hex, '000000000000000000000000') === 0 || strpos($hex, '00000000000000000000ffff') === 0) {
        return devdlink_ipv4_is_public(long2ip((int) hexdec(substr($hex, 24, 8))));
    }

    $b0 = hexdec(substr($hex, 0, 2));
    $b1 = hexdec(substr($hex, 2, 2));
    if ($b0 === 0xFF) {
        return false; // ff00::/8 multicast
    }
    if (($b0 & 0xFE) === 0xFC) {
        return false; // fc00::/7 unique local
    }
    if ($b0 === 0xFE && ($b1 & 0xC0) === 0x80) {
        return false; // fe80::/10 link local
    }
    $prefixes = array(
        '0064ff9b', // 64:ff9b::/96 NAT64
        '01000000', // 100::/64 discard-only
        '20010000', // 2001::/32 Teredo (embeds IPv4)
        '20010db8', // 2001:db8::/32 documentation
        '2002',     // 2002::/16 6to4 (embeds IPv4)
    );
    foreach ($prefixes as $p) {
        if (strpos($hex, $p) === 0) {
            return false;
        }
    }
    return true;
}

/**
 * Is this IP literal public? Dispatches on family; anything that is not a valid literal is
 * rejected (fail closed).
 *
 * @param string $ip
 * @return bool
 */
function devdlink_ip_is_public($ip)
{
    $ip = (string) $ip;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        return devdlink_ipv4_is_public($ip);
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        return devdlink_ipv6_is_public($ip);
    }
    return false;
}

/**
 * Resolve a hostname to every A + AAAA answer. Defined behind function_exists so the test
 * harness can supply a deterministic resolver.
 *
 * @param string $host ASCII hostname
 * @return string[] resolved IP literals (empty = resolution failed)
 */
if (!function_exists('devdlink_resolve_host')) {
    function devdlink_resolve_host($host)
    {
        $ips = array();
        $v4 = gethostbynamel((string) $host . '.');
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $recs = @dns_get_record((string) $host, DNS_AAAA); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- dns_get_record warns on transient DNS failures; an empty result is handled.
            if (is_array($recs)) {
                foreach ($recs as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = (string) $rec['ipv6'];
                    }
                }
            }
        }
        return array_values(array_unique($ips));
    }
}

/**
 * The single fail-closed outbound URL policy. Every scan, recheck and redirect hop goes
 * through this before any socket is opened. NOTHING is allowed by default: an unparsable
 * URL, an unresolvable host, a non-canonical numeric host, a port other than 80/443 or a
 * single non-public answer in DNS all reject.
 *
 * Encoded-loopback notations (decimal 2130706433, hex 0x7f000001, octal 0177.0.0.1,
 * short 127.1) are rejected at the syntax stage: a host whose last label does not start
 * with a letter must be a canonical dotted-quad, which none of those forms is.
 *
 * DNS rebinding: the resolved addresses are returned so the caller can pin them onto the
 * connection (see devdlink_check_url()). With the cURL transport that pin closes the
 * TOCTOU window; on the PHP streams fallback the transport resolves again and the window
 * cannot be closed from here - documented, not silently ignored.
 *
 * @param string $url
 * @return array{ok:bool, reason:string, host:string, port:int, ips:string[]}
 */
function devdlink_url_policy($url)
{
    $fail = function ($reason) use (&$host, &$port) {
        return array(
            'ok'     => false,
            'reason' => $reason,
            'host'   => isset($host) ? (string) $host : '',
            'port'   => isset($port) ? (int) $port : 0,
            'ips'    => array(),
        );
    };

    $url = (string) $url;
    $host = '';
    $port = 0;

    if ($url === '' || strlen($url) > 2048) {
        return $fail('url_invalid');
    }
    // Control characters, raw spaces and backslashes make the authority ambiguous: what this
    // parser sees and what the transport connects to can differ.
    if (preg_match('/[\x00-\x20\x7F]/', $url) || strpos($url, '\\') !== false) {
        return $fail('url_invalid');
    }
    if (!preg_match('~^(https?)://~i', $url, $m)) {
        return $fail('scheme');
    }
    $scheme = strtolower($m[1]);

    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || !isset($parts['scheme'])) {
        return $fail('url_invalid');
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return $fail('userinfo'); // credentials must never be sent to a scan target.
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if ($port !== 80 && $port !== 443) {
        return $fail('port');
    }

    $host = strtolower((string) $parts['host']);

    // IPv6 literal.
    if (strpos($host, '[') === 0 || strpos($host, ':') !== false) {
        $literal = trim($host, '[]');
        if ($literal === '' || strpos($literal, '%') !== false) {
            return $fail('host_syntax'); // zone identifiers are never a public target.
        }
        if (filter_var($literal, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $fail('host_syntax');
        }
        if (!devdlink_ipv6_is_public($literal)) {
            return $fail('private');
        }
        return array('ok' => true, 'reason' => 'ok', 'host' => $literal, 'port' => $port, 'ips' => array($literal));
    }

    // A trailing dot ("foo.example.") is refused outright: cURL applies a CURLOPT_RESOLVE pin
    // for "foo.example" to the request host "foo.example." NOT at all, so the pin built from
    // the stripped name would silently not apply and the rebinding window would reopen.
    if (substr($host, -1) === '.') {
        return $fail('host_syntax');
    }
    if ($host === '' || strlen($host) > 253 || strpos($host, '..') !== false) {
        return $fail('host_syntax');
    }

    // Non-ASCII: IDNA-normalize, or fail closed when the extension is unavailable.
    if (preg_match('/[^\x21-\x7E]/', $host)) {
        if (!function_exists('idn_to_ascii')) {
            return $fail('host_syntax');
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if (!is_string($ascii) || $ascii === '') {
            return $fail('host_syntax');
        }
        $host = strtolower($ascii);
    }

    $labels = explode('.', $host);
    $last = $labels[count($labels) - 1];

    // A last label that does not start with a letter is what every resolver treats as an IP
    // address literal. Only the canonical dotted-quad form is accepted; decimal, hexadecimal,
    // octal and short notations are rejected before any lookup.
    if (!preg_match('~^[a-z][a-z0-9-]{0,62}$~', $last)) {
        if (count($labels) !== 4) {
            return $fail('numeric_host');
        }
        foreach ($labels as $l) {
            if (!preg_match('~^(0|[1-9][0-9]{0,2})$~', $l) || (int) $l > 255) {
                return $fail('numeric_host');
            }
        }
        if (!devdlink_ipv4_is_public($host)) {
            return $fail('private');
        }
        return array('ok' => true, 'reason' => 'ok', 'host' => $host, 'port' => $port, 'ips' => array($host));
    }

    // Hostname: every label must be syntactically valid, and a single-label host (localhost,
    // an intranet short name) is never a public target.
    if (count($labels) < 2) {
        return $fail('host_syntax');
    }
    foreach ($labels as $l) {
        if (!preg_match('~^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$~', $l)) {
            return $fail('host_syntax');
        }
    }

    // DNS must succeed BEFORE the request, and every answer must be public.
    $ips = devdlink_resolve_host($host);
    if (!$ips) {
        return $fail('dns');
    }
    foreach ($ips as $ip) {
        if (!devdlink_ip_is_public($ip)) {
            return $fail('private');
        }
    }

    return array('ok' => true, 'reason' => 'ok', 'host' => $host, 'port' => $port, 'ips' => $ips);
}

/**
 * User-facing explanation for a policy rejection. Deliberately does NOT disclose the
 * resolved internal address.
 *
 * @param string $reason
 * @return string
 */
function devdlink_policy_message($reason)
{
    switch ((string) $reason) {
        case 'scheme':
            return __('Only http and https links can be checked.', 'devdome-link-monitor');
        case 'userinfo':
            return __('Links that carry a username or password are never requested.', 'devdome-link-monitor');
        case 'port':
            return __('Only ports 80 and 443 are checked.', 'devdome-link-monitor');
        case 'numeric_host':
            return __('The address is written in a non-standard numeric form - not checked.', 'devdome-link-monitor');
        case 'host_syntax':
            return __('The host name is not valid - not checked.', 'devdome-link-monitor');
        case 'dns':
            return __('The host name could not be resolved - not checked.', 'devdome-link-monitor');
        case 'private':
            return __('Target resolves to a private or reserved address - not checked.', 'devdome-link-monitor');
    }
    return __('Blocked by the outbound request policy - not checked.', 'devdome-link-monitor');
}

/**
 * SSRF guard (bool wrapper around devdlink_url_policy()).
 *
 * @param string $url
 * @return bool
 */
function devdlink_url_is_safe_target($url)
{
    $policy = devdlink_url_policy($url);
    return !empty($policy['ok']);
}

/** Would WordPress route a request for this URL through the configured HTTP proxy? */
function devdlink_request_is_proxied($url)
{
    if (!class_exists('WP_HTTP_Proxy')) {
        return false;
    }
    $proxy = new WP_HTTP_Proxy();
    return $proxy->is_enabled() && $proxy->send_through_proxy($url);
}

/**
 * Pin the validated addresses onto the cURL handle (CURLOPT_RESOLVE) so the connection can
 * only go to an address the policy already approved, closing the DNS-rebinding window
 * between validation and connect. Only requests that carry our own 'devdlink_pin' arg are
 * touched — every other plugin's HTTP request is untouched.
 *
 * @param resource|\CurlHandle $handle
 * @param array                $args
 * @return void
 */
function devdlink_curl_pin_resolve($handle, $args)
{
    $entry = devdlink_curl_resolve_entry(isset($args['devdlink_pin']) ? $args['devdlink_pin'] : null);
    if ($entry !== '' && defined('CURLOPT_RESOLVE')) {
        // This runs inside WordPress' own http_api_curl hook on the handle wp_remote_get()
        // opened: pinning the resolved IP (DNS-rebinding protection for the outbound link
        // check) has no wp_remote_* equivalent, so the single cURL option is set here.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- DNS pinning on WP's own cURL handle; no HTTP API equivalent.
        curl_setopt($handle, CURLOPT_RESOLVE, array($entry));
    }
}

/**
 * Build the CURLOPT_RESOLVE entry ("host:port:ip[,ip]") for a validated pin.
 *
 * @param array|null $pin
 * @return string '' when there is nothing to pin.
 */
function devdlink_curl_resolve_entry($pin)
{
    if (!is_array($pin) || empty($pin['host']) || empty($pin['port']) || empty($pin['ips'])) {
        return '';
    }
    $ips = array();
    foreach ((array) $pin['ips'] as $ip) {
        $ips[] = (strpos((string) $ip, ':') !== false) ? '[' . $ip . ']' : (string) $ip;
    }
    return $pin['host'] . ':' . (int) $pin['port'] . ':' . implode(',', $ips);
}
add_action('http_api_curl', 'devdlink_curl_pin_resolve', 10, 2);

/**
 * Classify a transport-level failure (no HTTP response at all) into an evidence class.
 * NONE of these is broken-link evidence: the check simply could not reach a verdict, so the
 * caller records the honest "unverified" result. The audited build turned every transport
 * error that did not look like a timeout into a 'fail', which is a false-positive path
 * straight to "broken".
 *
 * @param string $message WP_Error message from the HTTP API
 * @return string one of devdlink_error_classes()
 */
function devdlink_transport_error_class($message)
{
    $m = strtolower((string) $message);
    // cURL codes are matched with a boundary: "cURL error 60" must not be read as code 6.
    $curl = preg_match('~curl error (\d+)~', $m, $cm) ? (int) $cm[1] : 0;

    if ($curl === 35 || $curl === 60 || $curl === 51 || $curl === 58 || $curl === 83
        || strpos($m, 'ssl') !== false || strpos($m, 'certificate') !== false || strpos($m, 'tls') !== false) {
        return 'tls';
    }
    if ($curl === 6 || $curl === 5 || strpos($m, 'could not resolve') !== false
        || strpos($m, "couldn't resolve") !== false || strpos($m, 'name or service not known') !== false) {
        return 'dns';
    }
    if ($curl === 28 || strpos($m, 'timed out') !== false || strpos($m, 'timeout') !== false) {
        return 'timeout';
    }
    if ($curl === 7 || $curl === 56 || strpos($m, 'connection refused') !== false
        || strpos($m, 'connection reset') !== false || strpos($m, 'failed to connect') !== false) {
        return 'connect';
    }
    return 'protocol';
}

/**
 * Check a single URL. HEAD first, GET fallback on 405/403, manual redirect chain (max 5
 * hops, loop detection).
 *
 * Status policy by response class:
 *   2xx                     -> ok (or redirect when hops were followed)
 *   3xx                     -> followed manually, reported as redirect with the chain
 *   401                     -> blocked/auth      (access restricted, NEVER broken)
 *   403 after a real GET    -> blocked/antibot   (bot protection, NEVER broken)
 *   404 / 410               -> fail/gone         (the strongest broken evidence)
 *   429                     -> blocked/ratelimit (NEVER broken; Retry-After is reported)
 *   other 4xx               -> fail/http_4xx
 *   5xx                     -> fail/http_5xx     (transient until a separated second failure)
 *   999                     -> blocked/antibot
 *   DNS/TLS/connect/timeout -> timeout (unverified) with a distinct class
 *   policy refusal          -> blocked/policy    (never a broken-link claim)
 *
 * @param string $url
 * @return array{result:string, code:int, final_url:string, hops:int, error:string, class:string}
 *         result: ok | fail | redirect | timeout | blocked
 */
function devdlink_check_url($url)
{
    $timeout = max(3, min(30, devdlink_get_int('check_timeout', 10)));
    $default_ua = 'Mozilla/5.0 (compatible; DevDomeLinkMonitor/' . DEVDLINK_VERSION . '; +https://devdome.com)';
    $ua = (string) devdlink_get_setting('user_agent', $default_ua);
    if ($ua === '') {
        $ua = $default_ua;
    }

    $args = array(
        'timeout'     => $timeout,
        'redirection' => 0,
        'sslverify'   => true,
        'user-agent'  => $ua,
        // Never carry this site's cookies or auth headers to a scan target.
        'cookies'     => array(),
        'headers'     => array(),
    );

    // Fail closed without cURL: only the cURL transport can pin the validated addresses
    // (CURLOPT_RESOLVE); over PHP streams a rebinding answer between validation and connect
    // cannot be prevented, so the link is reported unverified rather than fetched unpinned.
    if (!function_exists('curl_init') || !defined('CURLOPT_RESOLVE')) {
        return array(
            'result'    => 'timeout',
            'code'      => 0,
            'final_url' => '',
            'hops'      => 0,
            'error'     => __('Link checks need the cURL extension (address pinning); this host has none, so the link was not verified.', 'devdome-link-monitor'),
            'class'     => 'transport',
        );
    }

    $current = (string) $url;
    $visited = array($current => true);
    $hops = 0;
    $first_redirect_code = 0;

    // Bounded: initial request + max 5 redirect hops.
    for ($guard = 0; $guard < 8; $guard++) {
        // SSRF guard on EVERY hop — the initial URL comes from post_content and each redirect
        // target is attacker-controllable, so private/reserved targets are never fetched.
        $policy = devdlink_url_policy($current);
        if (empty($policy['ok'])) {
            return array(
                'result'    => 'blocked',
                'code'      => 0,
                'final_url' => $hops > 0 ? $current : '',
                'hops'      => $hops,
                'error'     => devdlink_policy_message($policy['reason']),
                'class'     => 'policy',
            );
        }
        // Through an HTTP proxy (WP_PROXY_HOST) the PROXY resolves the name, so the address pin
        // below cannot apply: fail closed to unverified for that target instead of trusting it.
        if (devdlink_request_is_proxied($current)) {
            return array(
                'result'    => 'timeout',
                'code'      => 0,
                'final_url' => $hops > 0 ? $current : '',
                'hops'      => $hops,
                'error'     => __('This site sends HTTP requests through a proxy, where the resolved address cannot be pinned - the link was not verified.', 'devdome-link-monitor'),
                'class'     => 'transport',
            );
        }
        // Pin the approved addresses onto this request (cURL transport) so a rebinding answer
        // between validation and connect cannot redirect the socket to an internal host.
        $args['devdlink_pin'] = array(
            'host' => $policy['host'],
            'port' => $policy['port'],
            'ips'  => $policy['ips'],
        );
        $resp = wp_remote_head($current, $args);
        $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
        $was_head = true;

        // Any HEAD failure (405 refused, 403 gated, 404/5xx from a CDN that mishandles HEAD)
        // is re-requested as a real, size-capped GET, and THAT response goes through the same
        // classifier as any other: 2xx ok, 3xx followed, 401/403/429/999 blocked, transport
        // error unverified, 404/410/5xx a confirmed failure. A HEAD result never condemns.
        if (!is_wp_error($resp) && $code >= 400 && !in_array($code, array(401, 429, 999), true)) {
            $get_args = $args;
            $get_args['limit_response_size'] = 131072;
            $resp = wp_remote_get($current, $get_args);
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            $was_head = false;
        }

        if (is_wp_error($resp)) {
            $msg = (string) $resp->get_error_message();
            // No HTTP response at all: DNS, TLS, connect, timeout and protocol failures are
            // each recorded as their own evidence class and NONE of them is broken evidence.
            return array(
                'result'    => 'timeout',
                'code'      => 0,
                'final_url' => $hops > 0 ? $current : '',
                'hops'      => $hops,
                'error'     => devdlink_truncate(devdlink_redact_secrets($msg), 500),
                'class'     => devdlink_transport_error_class($msg),
            );
        }

        if ($code === 999) {
            // LinkedIn-style anti-bot response: we honestly cannot verify this link.
            return array(
                'result'    => 'blocked',
                'code'      => $code,
                'final_url' => $hops > 0 ? $current : '',
                'hops'      => $hops,
                'error'     => __('Anti-bot response (999) - the link could not be verified.', 'devdome-link-monitor'),
                'class'     => 'antibot',
            );
        }

        if ($code === 401) {
            // Authentication required. The link may be perfectly fine for a signed-in visitor,
            // so this can never become a broken-link claim.
            return array(
                'result'    => 'blocked',
                'code'      => $code,
                'final_url' => $hops > 0 ? $current : '',
                'hops'      => $hops,
                'error'     => __('The target requires authentication (401) - it was not verified.', 'devdome-link-monitor'),
                'class'     => 'auth',
            );
        }

        if ($code === 429) {
            $retry = wp_remote_retrieve_header($resp, 'retry-after');
            if (is_array($retry)) {
                $retry = reset($retry);
            }
            $retry = devdlink_truncate(preg_replace('/[^A-Za-z0-9 ,:+\-]/', '', (string) $retry), 60);
            return array(
                'result'    => 'blocked',
                'code'      => $code,
                'final_url' => $hops > 0 ? $current : '',
                'hops'      => $hops,
                'error'     => $retry !== ''
                    ? sprintf(
                        /* translators: %s: the target server's Retry-After value. */
                        __('Rate limited (429). The server asked us to retry after %s - it was not verified.', 'devdome-link-monitor'),
                        $retry
                    )
                    : __('Rate limited (429) - the link was not verified.', 'devdome-link-monitor'),
                'class'     => 'ratelimit',
            );
        }

        if ($code === 403) {
            // Still 403 after the GET fallback: treat as blocked, never broken.
            return array(
                'result'    => 'blocked',
                'code'      => $code,
                'final_url' => $hops > 0 ? $current : '',
                'hops'      => $hops,
                'error'     => __('Access denied (403) after a full GET - likely bot protection.', 'devdome-link-monitor'),
                'class'     => 'antibot',
            );
        }

        if ($code >= 200 && $code < 300) {
            if ($hops > 0) {
                return array(
                    'result'    => 'redirect',
                    'code'      => $first_redirect_code,
                    'final_url' => $current,
                    'hops'      => $hops,
                    'error'     => '',
                    'class'     => 'none',
                );
            }
            return array('result' => 'ok', 'code' => $code, 'final_url' => '', 'hops' => 0, 'error' => '', 'class' => 'none');
        }

        if ($code >= 300 && $code < 400) {
            $location = wp_remote_retrieve_header($resp, 'location');
            if (is_array($location)) {
                $location = reset($location);
            }
            $location = trim((string) $location);
            if ($location === '') {
                return array(
                    'result'    => 'fail',
                    'code'      => $code,
                    'final_url' => $hops > 0 ? $current : '',
                    'hops'      => $hops,
                    'error'     => __('Redirect response without a Location header.', 'devdome-link-monitor'),
                    'class'     => 'protocol',
                );
            }
            if (!preg_match('~^https?://~i', $location)) {
                $location = WP_Http::make_absolute_url($location, $current);
            }
            if ($first_redirect_code === 0) {
                $first_redirect_code = $code;
            }
            if (isset($visited[$location])) {
                return array(
                    'result'    => 'fail',
                    'code'      => $code,
                    'final_url' => $location,
                    'hops'      => $hops,
                    'error'     => __('Redirect loop detected.', 'devdome-link-monitor'),
                    'class'     => 'protocol',
                );
            }
            $hops++;
            if ($hops > 5) {
                return array(
                    'result'    => 'fail',
                    'code'      => $code,
                    'final_url' => $location,
                    'hops'      => $hops,
                    'error'     => __('Too many redirects (more than 5 hops).', 'devdome-link-monitor'),
                    'class'     => 'protocol',
                );
            }
            $visited[$location] = true;
            $current = $location;
            continue;
        }

        // Only a GET reaches this point (every HEAD failure was re-requested above).
        // 404/410 is the strongest broken evidence there is; the rest is
        // separated so the admin can see WHY before the two-strike rule condemns a link.
        if ($code === 404 || $code === 410) {
            $class = 'gone';
        } elseif ($code >= 500) {
            $class = 'http_5xx';
        } elseif ($code >= 400) {
            $class = 'http_4xx';
        } else {
            $class = 'protocol';
        }
        return array(
            'result'    => 'fail',
            'code'      => $code,
            'final_url' => $hops > 0 ? $current : '',
            'hops'      => $hops,
            'error'     => sprintf(
                /* translators: %d: HTTP status code. */
                __('HTTP %d response.', 'devdome-link-monitor'),
                $code
            ),
            'class'     => $class,
        );
    }

    // Unreachable in practice (the hop cap above exits first) — safety net.
    return array(
        'result'    => 'fail',
        'code'      => 0,
        'final_url' => $current,
        'hops'      => $hops,
        'error'     => __('Too many redirects (more than 5 hops).', 'devdome-link-monitor'),
        'class'     => 'protocol',
    );
}

/**
 * Is a failing recheck allowed to promote this row to 'broken'? Only when the previous
 * failing observation is real (a recorded checked_at) AND at least devdlink_strike_gap()
 * seconds old. Fail closed: no timestamp, or a timestamp from this same processing burst,
 * means the row stays 'suspect'.
 *
 * @param object $link_row
 * @return bool
 */
function devdlink_strike_is_separated($link_row)
{
    $prev = isset($link_row->checked_at) ? (string) $link_row->checked_at : '';
    if ($prev === '' || $prev === '0000-00-00 00:00:00') {
        return false;
    }
    $prev_ts = strtotime($prev);
    if (!$prev_ts) {
        return false;
    }
    return (strtotime(current_time('mysql')) - $prev_ts) >= devdlink_strike_gap();
}

/**
 * Persist a check result on a link row. Two-strikes rule lives here:
 *   fail in phase 'check'   -> status 'suspect', fail_count = 1 (never broken on strike one)
 *   fail in phase 'recheck' -> status 'broken' ONLY when the row already carries a real
 *                              strike (fail_count >= 1) AND that strike is at least
 *                              devdlink_strike_gap() seconds old; otherwise it stays
 *                              'suspect' (a same-burst look keeps its ORIGINAL checked_at
 *                              so the separation clock is not reset; a row with no prior
 *                              strike records this failure as strike ONE)
 *   timeout                 -> status 'timeout' in BOTH phases (never broken)
 *   ok / redirect           -> stored directly AND fail_count resets to 0 - a link that
 *                              recovered must fail twice AGAIN before it can be broken
 *   blocked                 -> stored directly, fail_count untouched ('blocked' also covers
 *                              a policy/SSRF rejection, which must never become a
 *                              broken-link claim).
 *
 * @param object $link_row row from the links table
 * @param array  $res      result from devdlink_check_url()
 * @param string $phase    check | recheck
 * @return string the status written
 */
function devdlink_apply_result($link_row, $res, $phase)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_links';
    $fail_count = (int) $link_row->fail_count;
    $keep_checked_at = false;

    switch ((string) $res['result']) {
        case 'ok':
            $status = 'ok';
            $fail_count = 0; // confirmed reachable: any earlier strike is stale evidence
            break;
        case 'redirect':
            $status = 'redirect';
            $fail_count = 0;
            break;
        case 'blocked':
            $status = 'blocked'; // could-not-verify: neither evidence of failure nor of recovery
            break;
        case 'timeout':
            $status = 'timeout'; // NEVER broken.
            break;
        case 'fail':
        default:
            if ($phase === 'recheck' && $fail_count >= 1 && devdlink_strike_is_separated($link_row)) {
                $status = 'broken';
                $fail_count++;
            } elseif ($phase === 'recheck' && $fail_count >= 1) {
                // Same-burst re-observation: not independent evidence, so no broken claim and
                // no clock reset.
                $status = 'suspect';
                $keep_checked_at = true;
            } else {
                // First REAL failure evidence. Also the path for a timeout/blocked row that
                // reaches the recheck phase without a recorded strike: its first hard failure
                // is strike ONE, never a promotion.
                $status = 'suspect';
                $fail_count = 1;
            }
            break;
    }

    $class = isset($res['class']) ? (string) $res['class'] : 'none';
    if (!in_array($class, devdlink_error_classes(), true)) {
        $class = 'none';
    }

    $data = array(
        'status'        => $status,
        'http_code'     => max(0, min(65535, (int) $res['code'])),
        'redirect_url'  => (string) $res['final_url'],
        'redirect_hops' => max(0, min(255, (int) $res['hops'])),
        'fail_count'    => min(255, max(0, $fail_count)),
        'error_class'   => $class,
        'checked_at'    => current_time('mysql'),
        'error_message' => devdlink_truncate(devdlink_redact_secrets((string) $res['error']), 500),
    );
    $format = array('%s', '%d', '%s', '%d', '%d', '%s', '%s', '%s');
    if ($keep_checked_at) {
        unset($data['checked_at']);
        $format = array('%s', '%d', '%s', '%d', '%d', '%s', '%s');
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table result write.
    $written = $wpdb->update($table, $data, array('id' => (int) $link_row->id), $format, array('%d'));
    if ($written === false) {
        return false; // the result could not be persisted: the caller must not count this link as checked.
    }

    return $status;
}

/**
 * Synchronous one-off re-check for the REST "Re-check now" action. A failure maps like
 * phase 'recheck' only when the row already has a strike (fail_count >= 1); otherwise it
 * is strike one and becomes 'suspect'.
 *
 * @param int $link_id
 * @return array|WP_Error the updated link row as an associative array
 */
function devdlink_recheck_single($link_id)
{
    $row = devdlink_check_get_link((int) $link_id);
    if (!$row) {
        return new WP_Error('devdlink_not_found', __('Link not found.', 'devdome-link-monitor'));
    }

        $res = devdlink_check_url((string) $row->url);
    $phase = ((int) $row->fail_count >= 1) ? 'recheck' : 'check';
    if (devdlink_apply_result($row, $res, $phase) === false) {
        return new WP_Error('devdlink_db_write', __('The link was checked but the result could not be saved.', 'devdome-link-monitor'), array('status' => 500));
    }
    if (function_exists('devdlink_refresh_summary')) {
        devdlink_refresh_summary();
    }

    $fresh = devdlink_check_get_link((int) $link_id);
    if (!$fresh) {
        return new WP_Error('devdlink_not_found', __('Link not found.', 'devdome-link-monitor'));
    }
    return (array) $fresh;
}

/**
 * The literal forms an editor could have stored for a NORMALIZED (absolute) URL: the
 * absolute URL itself, the protocol-relative form ("//host/path"), and — for internal
 * links — the root-relative form ("/path"). devdlink_normalize_url() resolves all of
 * these to the absolute URL before persisting, so content matching must try each.
 *
 * @param string $url normalized absolute URL from the links table
 * @return string[]
 */
function devdlink_url_literal_forms($url)
{
    $url = (string) $url;
    $forms = array($url);
    $parts = wp_parse_url($url);
    if (!is_array($parts) || empty($parts['host'])) {
        return $forms;
    }
    $tail = (isset($parts['path']) ? (string) $parts['path'] : '')
        . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');
    $authority = (string) $parts['host'] . (!empty($parts['port']) ? ':' . (int) $parts['port'] : '');
    $forms[] = '//' . $authority . $tail;
    if ($tail !== '' && $tail[0] === '/' && devdlink_is_internal($url)) {
        $forms[] = $tail;
    }
    return array_values(array_unique($forms));
}

/**
 * Does the post's CURRENT content still contain the URL, judged by the same extractor the
 * scan uses (quoted, unquoted and entity-encoded attributes alike)? Called after every
 * rewrite: a replacement that missed a spelling must never let the tracked row change.
 *
 * @param int    $post_id
 * @param string $url       the tracked (normalized) URL
 * @param bool   $href_only only <a href> occurrences count (unlink leaves <img> alone)
 * @return bool
 */
function devdlink_post_still_has_url($post_id, $url, $href_only = false)
{
    $post = get_post((int) $post_id);
    if (!$post || !function_exists('devdlink_extract_from_html')) {
        return false;
    }
    foreach (devdlink_extract_from_html((string) $post->post_content) as $hit) {
        if ($href_only && $hit['type'] !== 'href') {
            continue;
        }
        $seen = function_exists('devdlink_normalize_url') ? devdlink_normalize_url((string) $hit['url'], (int) $post_id) : (string) $hit['url'];
        if ((string) $seen === (string) $url) {
            return true;
        }
    }
    return false;
}

/**
 * Append one entry to the bounded content-mutation audit trail (actor, time, operation,
 * authorized source ids, redacted old/new value, outcome, correlation id). Kept in the
 * settings store, newest 100 entries, so a rewrite of published content is always reviewable.
 *
 * @param string $operation edit_url | unlink
 * @param int    $link_id
 * @param int[]  $post_ids  the posts that were authorized for the operation
 * @param string $old
 * @param string $new
 * @param string $outcome   ok | partial | failed | refused
 * @param string $detail
 * @return string the correlation id
 */
function devdlink_record_mutation($operation, $link_id, $post_ids, $old, $new, $outcome, $detail = '')
{
    $log = devdlink_get_array('mutation_log');
    $correlation = substr(md5(uniqid('lm', true)), 0, 12);
    $log[] = array(
        'at'          => time(),
        'user_id'     => (int) get_current_user_id(),
        'operation'   => (string) $operation,
        'link_id'     => (int) $link_id,
        'post_ids'    => array_map('intval', (array) $post_ids),
        'old'         => devdlink_truncate(devdlink_redact_secrets((string) $old), 300),
        'new'         => devdlink_truncate(devdlink_redact_secrets((string) $new), 300),
        'old_hash'    => hash('sha256', (string) $old),
        'outcome'     => (string) $outcome,
        'detail'      => devdlink_truncate((string) $detail, 200),
        'correlation' => $correlation,
    );
    if (count($log) > 100) {
        $log = array_slice($log, -100);
    }
    devdlink_update_setting('mutation_log', $log);
    return $correlation;
}

/**
 * Replace an exact attribute-quoted URL in one post's content ("OLD" and 'OLD' forms, every
 * literal form the editor could have stored — absolute, protocol-relative, root-relative —
 * plus the HTML-entity-encoded variant the editor stores for URLs with & in the query
 * string). The replacement is always the absolute new URL.
 *
 * The post is re-read HERE, immediately before the write, so an occurrence that was edited
 * away between the scan and this call simply does not match and nothing is written. The
 * wp_update_post() return value is checked (it can fail on a filter, a revision error or a
 * database problem) and the saved content is re-read and verified.
 *
 * @param int    $post_id
 * @param string $old_url
 * @param string $new_url
 * @return array{count:int, error:WP_Error|null}
 */
function devdlink_replace_url_in_post($post_id, $old_url, $new_url)
{
    $post = get_post((int) $post_id);
    if (!$post) {
        return array('count' => 0, 'error' => null);
    }
    $old_url = (string) $old_url;
    $new_url = (string) $new_url;
    if ($old_url === '' || $old_url === $new_url) {
        return array('count' => 0, 'error' => null);
    }

    // Only <a href> / <img src> attribute VALUES are rewritten (shared scanner): the same
    // string in a block comment, a data attribute, <code> or visible text is never touched.
    // An occurrence is whatever the scanner would resolve to the tracked URL from THIS post:
    // absolute, protocol-relative, root-relative or document-relative (../pricing, ./about,
    // contact). No guessing of literal spellings.
    $matcher = function ($candidate) use ($old_url, $post_id) {
        return function_exists('devdlink_normalize_url') && devdlink_normalize_url((string) $candidate, (int) $post_id) === (string) $old_url;
    };
    $rw      = devdlink_rewrite_url_in_html((string) $post->post_content, devdlink_url_literal_forms($old_url), $new_url, $matcher);
    $content = $rw['html'];
    $count   = (int) $rw['count'];

    if ($count === 0) {
        return array('count' => 0, 'error' => null);
    }

    $saved = wp_update_post(array(
        'ID'           => (int) $post_id,
        'post_content' => wp_slash($content),
    ), true);
    if (is_wp_error($saved) || !$saved) {
        return array(
            'count' => 0,
            'error' => new WP_Error(
                'devdlink_save_failed',
                is_wp_error($saved) ? $saved->get_error_message() : __('The post could not be saved.', 'devdome-link-monitor')
            ),
        );
    }

    // Read it back: a filter may have rejected or rewritten the content we just handed over.
    $fresh = get_post((int) $post_id);
    if (!$fresh || (string) $fresh->post_content !== $content) {
        return array(
            'count' => 0,
            'error' => new WP_Error('devdlink_save_failed', __('The saved content does not match what was submitted - nothing was changed.', 'devdome-link-monitor')),
        );
    }
    // The extractor has the last word: if it still finds the old URL in this post (a spelling
    // the rewrite did not cover), the post counts as NOT fixed and the tracked row stays.
    if (devdlink_post_still_has_url((int) $post_id, $old_url, false)) {
        return array(
            'count' => 0,
            'error' => new WP_Error('devdlink_still_present', __('The old URL is still present in this post after the rewrite - the tracked record was not changed.', 'devdome-link-monitor')),
        );
    }

    return array('count' => $count, 'error' => null);
}

/**
 * Object-level authorization for a content mutation. The plugin capability
 * (devdlink_capability, filterable) decides who may open the screen; it can NEVER stand in
 * for WordPress' own per-post authorization. Every source post of the link must pass
 * current_user_can('edit_post', $post_id) or the whole group is refused — a partially applied
 * group would leave content half-rewritten and leak which posts exist.
 *
 * @param int        $link_id
 * @param int[]|null $post_ids already-resolved source posts (null loads them)
 * @return true|WP_Error
 */
function devdlink_authorize_link_sources($link_id, $post_ids = null)
{
    if (!is_array($post_ids)) {
        $post_ids = devdlink_check_source_post_ids((int) $link_id);
    }
    foreach ($post_ids as $pid) {
        if (!current_user_can('edit_post', (int) $pid)) {
            // Deliberately generic: never disclose which post the caller cannot reach.
            return new WP_Error('devdlink_forbidden', __('You are not allowed to edit the content this link appears in.', 'devdome-link-monitor'));
        }
    }
    return true;
}

/**
 * Edit a link's URL everywhere it appears: the attribute value is rewritten in every source post (shared HTML scanner), then
 * reset + synchronously re-check the links row.
 *
 * @param int    $link_id
 * @param string $new_url
 * @return array|WP_Error array('updated_posts' => int)
 */
function devdlink_edit_link_url($link_id, $new_url)
{
    global $wpdb;

    $row = devdlink_check_get_link((int) $link_id);
    if (!$row) {
        return new WP_Error('devdlink_not_found', __('Link not found.', 'devdome-link-monitor'));
    }

    $post_ids = devdlink_check_source_post_ids((int) $row->id);
    $authorized = devdlink_authorize_link_sources((int) $row->id, $post_ids);
    if (is_wp_error($authorized)) {
        devdlink_record_mutation('edit_url', (int) $row->id, $post_ids, (string) $row->url, (string) $new_url, 'refused', 'object authorization');
        return $authorized;
    }

    $new_url = esc_url_raw(trim((string) $new_url));
    if ($new_url === '' || !preg_match('~^https?://~i', $new_url)) {
        return new WP_Error('devdlink_bad_url', __('Enter a valid http(s) URL.', 'devdome-link-monitor'));
    }

    $table = $wpdb->prefix . 'devdlink_links';
    $new_hash = devdlink_url_hash($new_url);

    if ($new_hash !== (string) $row->url_hash) {
        // The (scan_id, url_hash) key is unique — refuse an edit that would collide.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table uniqueness check; values bound via prepare.
        $dupe = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE scan_id = %d AND url_hash = %s AND id != %d",
            (int) $row->scan_id,
            $new_hash,
            (int) $row->id
        ));
        if ($dupe) {
            return new WP_Error('devdlink_duplicate', __('That URL is already tracked in this scan.', 'devdome-link-monitor'));
        }
    }

    $updated_posts = 0;
    $failed_posts = 0;
    foreach ($post_ids as $pid) {
        $out = devdlink_replace_url_in_post($pid, (string) $row->url, $new_url);
        if ($out['error'] instanceof WP_Error) {
            $failed_posts++;
            continue;
        }
        if ((int) $out['count'] > 0) {
            $updated_posts++;
        }
    }

    if ($failed_posts > 0) {
        // A precise partial outcome, never a silent success: some posts were rewritten and at
        // least one could not be saved. The links row is left untouched so the stored state
        // still matches the worst case (the old URL is still live somewhere).
        devdlink_record_mutation('edit_url', (int) $row->id, $post_ids, (string) $row->url, $new_url, 'partial', $failed_posts . ' post(s) failed to save');
        return new WP_Error(
            'devdlink_partial',
            sprintf(
                /* translators: 1: number of posts changed, 2: number of posts that failed. */
                __('%1$d post(s) were updated but %2$d could not be saved, so the old link may still be live there. The tracked link record was not changed - please retry to finish the remaining post(s).', 'devdome-link-monitor'),
                $updated_posts,
                $failed_posts
            )
        );
    }

    if ($updated_posts === 0) {
        // Nothing was rewritten — keep the row in its old state instead of silently claiming
        // the link was fixed while the published content still holds the old URL.
        devdlink_record_mutation('edit_url', (int) $row->id, $post_ids, (string) $row->url, $new_url, 'failed', 'no matching occurrence');
        return new WP_Error('devdlink_not_in_content', __('That URL could not be located in the source content - nothing was changed. It may have been edited since the last scan; re-scan and try again.', 'devdome-link-monitor'));
    }
    unset($save_error);

    $host = strtolower((string) devdlink_url_host($new_url));
    if (strlen($host) > 191) {
        $host = substr($host, 0, 191);
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table URL rewrite + status reset.
    $written = $wpdb->update($table, array(
        'url'           => $new_url,
        'url_hash'      => $new_hash,
        'host'          => $host,
        'is_internal'   => devdlink_is_internal($new_url) ? 1 : 0,
        'status'        => 'pending',
        'http_code'     => 0,
        'redirect_url'  => '',
        'redirect_hops' => 0,
        'fail_count'    => 0,
        'error_message' => '',
    ), array('id' => (int) $row->id), array('%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s'), array('%d'));
    if ($written === false) {
        // Content is already rewritten; the tracked row is now stale. Say so and ask for a
        // rescan instead of answering "ok" over an inconsistent state.
        devdlink_record_mutation('edit_url', (int) $row->id, $post_ids, (string) $row->url, $new_url, 'inconsistent', 'content updated, links row write failed');
        devdlink_update_setting('rescan_needed', 1);
        return new WP_Error('devdlink_db_write', __('The content was updated but the tracked link record could not be saved. Run a scan to resync the list.', 'devdome-link-monitor'));
    }

    $correlation = devdlink_record_mutation('edit_url', (int) $row->id, $post_ids, (string) $row->url, $new_url, 'ok', $updated_posts . ' post(s) updated');

    // Only recheck once the content mutation has actually committed.
    devdlink_recheck_single((int) $row->id);

    return array('updated_posts' => $updated_posts, 'correlation' => $correlation);
}

/**
 * Remove the <a> wrapper around a link everywhere it appears, keeping the anchor text.
 * href links only. Marks the links row 'dismissed'.
 *
 * @param int $link_id
 * @return array|WP_Error array('updated_posts' => int)
 */
function devdlink_unlink($link_id)
{
    global $wpdb;

    $row = devdlink_check_get_link((int) $link_id);
    if (!$row) {
        return new WP_Error('devdlink_not_found', __('Link not found.', 'devdome-link-monitor'));
    }
    // Unlink needs at least one <a href> occurrence (an image-only URL has nothing to unlink).
    $sources_t = $wpdb->prefix . 'devdlink_link_sources';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal link_sources read; id bound via prepare.
    $has_href = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$sources_t} WHERE link_id = %d AND usage_type = 'href'", (int) $row->id));
    if (!$has_href && (string) $row->link_type !== 'href') {
        return new WP_Error('devdlink_not_href', __('Only anchor links can be unlinked.', 'devdome-link-monitor'));
    }

    // Unlink touches <a href> occurrences only, so only the posts holding one are authorized:
    // an image-only use of the same URL in another post is not being edited.
    $post_ids = devdlink_check_source_post_ids((int) $row->id, 'href');
    if (!$post_ids) {
        $post_ids = devdlink_check_source_post_ids((int) $row->id);
    }
    $authorized = devdlink_authorize_link_sources((int) $row->id, $post_ids);
    if (is_wp_error($authorized)) {
        devdlink_record_mutation('unlink', (int) $row->id, $post_ids, (string) $row->url, '', 'refused', 'object authorization');
        return $authorized;
    }

    // Every literal form the editor could have stored (absolute, protocol-relative,
    // root-relative); the shared scanner matches them quoted, bare or entity-encoded.
    $forms = devdlink_url_literal_forms((string) $row->url);

    $updated_posts = 0;
    $failed_posts = 0;
    foreach ($post_ids as $pid) {
        // Re-read immediately before the write: an occurrence edited away since the scan
        // simply will not match, and nothing is saved for that post.
        $post = get_post($pid);
        if (!$post) {
            continue;
        }
        $content = (string) $post->post_content;
        $pid_for_match = (int) $pid;
        $tracked_url = (string) $row->url;
        $matcher = function ($candidate) use ($tracked_url, $pid_for_match) {
            return function_exists('devdlink_normalize_url') && devdlink_normalize_url((string) $candidate, $pid_for_match) === $tracked_url;
        };
        $ul = devdlink_unlink_in_html($content, $forms, $matcher);
        $new_content = $ul['html'];
        $n = (int) $ul['count'];
        if ($n < 1 || $new_content === $content) {
            continue;
        }
        $saved = wp_update_post(array(
            'ID'           => (int) $pid,
            'post_content' => wp_slash($new_content),
        ), true);
        if (is_wp_error($saved) || !$saved) {
            $failed_posts++;
            continue;
        }
        $fresh = get_post((int) $pid);
        if (!$fresh || (string) $fresh->post_content !== $new_content) {
            $failed_posts++;
            continue;
        }
        // The extractor has the last word: an <a href> the pattern missed keeps this post
        // "not fixed", so the tracked row is never dismissed over live content.
        if (devdlink_post_still_has_url((int) $pid, (string) $row->url, true)) {
            $failed_posts++;
            continue;
        }
        $updated_posts++;
    }

    if ($failed_posts > 0) {
        devdlink_record_mutation('unlink', (int) $row->id, $post_ids, (string) $row->url, '', 'partial', $failed_posts . ' post(s) failed to save');
        return new WP_Error(
            'devdlink_partial',
            sprintf(
                /* translators: 1: number of posts changed, 2: number of posts that failed. */
                __('%1$d post(s) were updated but %2$d could not be saved, so the old link may still be live there. The tracked link record was not changed - please retry to finish the remaining post(s).', 'devdome-link-monitor'),
                $updated_posts,
                $failed_posts
            )
        );
    }

    if ($updated_posts === 0) {
        // Nothing was rewritten — keep the row in its old state instead of silently marking
        // it dismissed while the published content still holds the link.
        devdlink_record_mutation('unlink', (int) $row->id, $post_ids, (string) $row->url, '', 'failed', 'no matching occurrence');
        return new WP_Error('devdlink_not_in_content', __('That link was not found in the source content - nothing was changed. It may have been edited since the last scan; re-scan and try again.', 'devdome-link-monitor'));
    }

    $table = $wpdb->prefix . 'devdlink_links';
    // Only the <a href> occurrences were removed from content. An <img src> use of the same
    // URL (mixed use) is still published, so the record must stay live (and broken) for it:
    // drop the href occurrence rows, and dismiss only when nothing else refers to the URL.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal occurrence rows for this link.
    $deleted = $wpdb->delete($sources_t, array('link_id' => (int) $row->id, 'usage_type' => 'href'), array('%d', '%s'));
    if ($deleted === false) {
        devdlink_record_mutation('unlink', (int) $row->id, $post_ids, (string) $row->url, '', 'inconsistent', 'content updated, occurrence rows write failed');
        devdlink_update_setting('rescan_needed', 1);
        return new WP_Error('devdlink_db_write', __('The content was updated but the tracked link record could not be saved. Run a scan to resync the list.', 'devdome-link-monitor'));
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal occurrence rows for this link.
    $remaining_types = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT usage_type FROM {$sources_t} WHERE link_id = %d", (int) $row->id));
    $remaining_types = array_values(array_filter(array_map('strval', (array) $remaining_types)));
    if ($remaining_types) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table type update.
        // The anchor text belonged to the <a> that is gone now; the row must not keep showing it.
        $written = $wpdb->update($table, array('link_type' => in_array('img', $remaining_types, true) ? 'img' : $remaining_types[0], 'anchor_text' => ''), array('id' => (int) $row->id), array('%s', '%s'), array('%d'));
        $outcome = $updated_posts . ' post(s) updated; URL still used as ' . implode('/', $remaining_types) . ', kept';
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table status update.
        $written = $wpdb->update($table, array('status' => 'dismissed'), array('id' => (int) $row->id), array('%s'), array('%d'));
        $outcome = $updated_posts . ' post(s) updated';
    }
    if ($written === false) {
        devdlink_record_mutation('unlink', (int) $row->id, $post_ids, (string) $row->url, '', 'inconsistent', 'content updated, links row write failed');
        devdlink_update_setting('rescan_needed', 1);
        return new WP_Error('devdlink_db_write', __('The content was updated but the tracked link record could not be saved. Run a scan to resync the list.', 'devdome-link-monitor'));
    }

    $correlation = devdlink_record_mutation('unlink', (int) $row->id, $post_ids, (string) $row->url, '', 'ok', $outcome);

    if (function_exists('devdlink_refresh_summary')) {
        devdlink_refresh_summary();
    }

    return array('updated_posts' => $updated_posts, 'correlation' => $correlation);
}

/**
 * Fetch one link row by id.
 *
 * @param int $link_id
 * @return object|null
 */
function devdlink_check_get_link($link_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_links';
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal links table single-row read; id bound via prepare.
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $link_id));
    return $row ? $row : null;
}

/**
 * Source post ids for a link.
 *
 * @param int $link_id
 * @return int[]
 */
function devdlink_check_source_post_ids($link_id, $usage_type = '')
{
    global $wpdb;
    $table = $wpdb->prefix . 'devdlink_link_sources';
    if ($usage_type !== '') {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal link_sources read; values bound via prepare.
        $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$table} WHERE link_id = %d AND usage_type = %s ORDER BY post_id ASC", (int) $link_id, $usage_type));
        return array_map('intval', (array) $ids);
    }
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal link_sources read; id bound via prepare.
    $ids = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT post_id FROM {$table} WHERE link_id = %d ORDER BY post_id ASC", (int) $link_id));
    return array_map('intval', (array) $ids);
}
