<?php
/**
 * Tenant ownership for media files (Phase 5.2).
 *
 * media/ is one physically shared directory, so ownership cannot be expressed
 * by the filesystem — it lives in media_meta.tenant_id and has to be enforced on
 * every management path that reads, overwrites or deletes a file. These helpers
 * are that enforcement; upload.php / delete.php / media_meta.php call them
 * instead of each rolling their own check.
 *
 * NOTE: media.php (the app's fetch endpoint) stays unauthenticated by design —
 * a signage device has no login. It serves any file by name, so media/ is not a
 * confidentiality boundary against someone who can guess a filename. What these
 * helpers do guarantee is that one customer cannot enumerate, overwrite or
 * delete another customer's media through the dashboard.
 */
require_once __DIR__ . '/auth.php';

/** Reject names that could escape media/. */
function tw_media_name_ok(string $name): bool
{
    return $name !== ''
        && strpbrk($name, "/\\") === false
        && strpos($name, '..') === false;
}

/**
 * Tenant that owns $filename: an int, or null when the file is unassigned
 * (the shared company pool) or has no media_meta row yet.
 */
function tw_media_owner(PDO $pdo, string $filename): ?int
{
    $s = $pdo->prepare('SELECT tenant_id FROM media_meta WHERE filename = ?');
    $s->execute([$filename]);
    $v = $s->fetchColumn();
    return ($v === false || $v === null) ? null : (int) $v;
}

/**
 * Assert the actor may overwrite or delete $filename.
 *
 * Internal staff may touch anything. A tenant-bound customer may only touch
 * files already assigned to their own tenant — never another tenant's, and never
 * an unassigned file from the company pool (those are ours, and letting a
 * customer claim or clobber them by guessing a name is exactly the hole this
 * closes).
 */
function tw_require_media_access(PDO $pdo, string $filename): void
{
    $own = tw_current_tenant_id();
    if ($own === null) {
        return; // internal staff
    }
    if (tw_media_owner($pdo, $filename) !== $own) {
        tw_json(['error' => 'forbidden'], 403);
    }
}
