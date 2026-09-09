<?php
/**
 * Settings storage — a single name/value table (the suite's Bot Protection /
 * Redirect Manager convention) with a process-level read cache. Arrays are
 * serialized on write and unserialized with allowed_classes => false on read.
 */

defined('ABSPATH') || exit;

function devdlink_settings_table()
{
    global $wpdb;
    return $wpdb->prefix . 'devdlink_settings';
}

/** Read a setting, falling back to $default when unset. Arrays are unserialized. */
function devdlink_get_setting($name, $default = '')
{
    global $wpdb;

    if (!isset($GLOBALS['devdlink_cache']) || !is_array($GLOBALS['devdlink_cache'])) {
        $GLOBALS['devdlink_cache'] = array();
        $table = devdlink_settings_table();
        $wpdb->last_error = '';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; whole-table read cached for the request in $GLOBALS.
        $rows = $wpdb->get_results("SELECT setting_name, setting_value FROM $table", ARRAY_A);
        if ((string) $wpdb->last_error !== '') {
            // A failed read is NOT an empty settings table: leave the cache unset so the next
            // call re-reads, and raise the flag so queue decisions (start, tick, cancel) refuse
            // to act on a view that would otherwise look like "no current job".
            unset($GLOBALS['devdlink_cache']);
            $GLOBALS['devdlink_settings_read_failed'] = true;
            return $default;
        }
        $GLOBALS['devdlink_settings_read_failed'] = false;
        if ($rows) {
            foreach ($rows as $r) {
                $GLOBALS['devdlink_cache'][$r['setting_name']] = $r['setting_value'];
            }
        }
    }

    if (!array_key_exists($name, $GLOBALS['devdlink_cache'])) {
        return $default;
    }

    $value = $GLOBALS['devdlink_cache'][$name];
    $unser = @unserialize($value, array('allowed_classes' => false));
    if ($unser !== false || $value === 'b:0;') {
        return $unser;
    }
    return $value;
}

/** Write a setting (insert or update). Arrays are serialized. Invalidates the cache. */
function devdlink_update_setting($name, $value)
{
    global $wpdb;
    $table = devdlink_settings_table();
    $stored = is_array($value) ? serialize($value) : $value;

    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- internal prefix table; values bound via prepare; settings table is not cacheable.
    $ok = $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (setting_name, setting_value) VALUES (%s, %s)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
        $name,
        $stored
    ));

    unset($GLOBALS['devdlink_cache']);
    // false = the database refused the write. Callers that persist queue state must not
    // pretend it happened (an orphan running scan with no job would be the result).
    return $ok !== false;
}

/** Convenience: read an array setting, always returning an array. */
function devdlink_get_array($name, $default = array())
{
    $v = devdlink_get_setting($name, $default);
    return is_array($v) ? $v : (array) $default;
}

/** Read an integer setting. */
function devdlink_get_int($name, $default = 0)
{
    return (int) devdlink_get_setting($name, $default);
}
