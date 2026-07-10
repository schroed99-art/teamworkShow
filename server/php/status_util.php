<?php
/**
 * Shared device online/offline status classifier.
 *
 * A device pulls playlist.php every ~60s, which stamps devices.last_seen = NOW().
 * We classify by how long ago that was:
 *   online  : seen within TW_ONLINE_WINDOW (allows a couple of missed pulls)
 *   offline : older than that
 *   alarm   : offline for longer than TW_ALARM_WINDOW (raise attention / email)
 *   never   : never seen (last_seen NULL)
 *
 * Functions only — no output. Safe to require from any endpoint or CLI script.
 */

const TW_ONLINE_WINDOW = 150;   // seconds (~2.5 pulls at 60s) still counts as online
const TW_ALARM_WINDOW  = 1800;  // seconds offline before it becomes an alarm (30 min)

/** Classify a device from the seconds elapsed since last_seen (null = never seen). */
function tw_device_status(?int $secondsSinceSeen): string
{
    if ($secondsSinceSeen === null) {
        return 'never';
    }
    if ($secondsSinceSeen < 0) {
        $secondsSinceSeen = 0; // clock skew guard
    }
    if ($secondsSinceSeen <= TW_ONLINE_WINDOW) {
        return 'online';
    }
    if ($secondsSinceSeen >= TW_ALARM_WINDOW) {
        return 'alarm';
    }
    return 'offline';
}

/** Human, German, compact "how long ago" label. */
function tw_ago_human(?int $s): string
{
    if ($s === null) {
        return 'nie';
    }
    if ($s < 0) {
        $s = 0;
    }
    if ($s < 60) {
        return 'vor ' . $s . ' s';
    }
    if ($s < 3600) {
        return 'vor ' . intdiv($s, 60) . ' min';
    }
    if ($s < 86400) {
        return 'vor ' . intdiv($s, 3600) . ' h';
    }
    return 'vor ' . intdiv($s, 86400) . ' Tg.';
}

/** Roll up several device statuses into one tenant-level status (worst wins). */
function tw_rollup_status(array $statuses): string
{
    if (in_array('alarm', $statuses, true)) {
        return 'alarm';
    }
    if (in_array('offline', $statuses, true) || in_array('never', $statuses, true)) {
        return 'offline';
    }
    if (in_array('online', $statuses, true)) {
        return 'online';
    }
    return 'none'; // no devices
}
