<?php
/**
 * Device CRUD.
 *   GET    ?tenant_id=  (or all) -> { devices: [...] }
 *   POST   {tenant_id, name?, standort?, anzeige_info?, presentation_id?, pairing_code?}
 *          -> creates a device (+ default widget row); pairing_code auto-generated if omitted
 *   PUT    {id, name?|standort?|anzeige_info?|presentation_id?|tenant_id?}
 *   DELETE ?id= (cascades slides via presentation? no — cascades widget_settings)
 */
require __DIR__ . '/auth.php';
require __DIR__ . '/status_util.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

/** Allowed device display formats; anything else falls back to portrait. */
const TW_DISPLAY_FORMATS = ['portrait', 'phone', 'landscape', 'tablet'];

function tw_display_format(mixed $v): string
{
    $v = strtolower(trim((string) $v));
    return in_array($v, TW_DISPLAY_FORMATS, true) ? $v : 'portrait';
}

/**
 * Zone config (Phase 5.3). 'split' shows a company zone plus the customer zone;
 * 'custom' (Vollausbau) drives a free-form per-format zone tree in zone_layout.
 */
const TW_ZONE_MODES = ['single', 'company', 'split', 'custom'];
const TW_ZONE_AXES  = ['rows', 'cols'];   // stacked vs. side by side

// Guardrails for the custom zone tree, so a hand-crafted PUT can't blow up the
// renderer with a pathologically deep or huge layout.
const TW_ZONE_MAX_DEPTH = 6;
const TW_ZONE_MAX_NODES = 64;

function tw_zone_mode(mixed $v): string
{
    $v = strtolower(trim((string) $v));
    return in_array($v, TW_ZONE_MODES, true) ? $v : 'single';
}

function tw_zone_axis(mixed $v): string
{
    $v = strtolower(trim((string) $v));
    return in_array($v, TW_ZONE_AXES, true) ? $v : 'rows';
}

/** The company zone's share, clamped so neither zone can collapse to nothing. */
function tw_zone_split(mixed $v): int
{
    $n = is_numeric($v) ? (int) $v : 70;
    return max(10, min(90, $n));
}

/**
 * Validate one node of a custom zone tree and return it in canonical form.
 * A node is either a split (axis + >=2 weighted children) or a leaf zone bound
 * to a source. $budget (by ref) caps the total node count across the whole tree.
 * Any structural problem answers 422 and exits (tw_json terminates the request).
 */
function tw_zone_node(mixed $node, PDO $pdo, int $depth, int &$budget): array
{
    if ($depth > TW_ZONE_MAX_DEPTH) {
        tw_json(['error' => 'zone_layout_too_deep'], 422);
    }
    if (--$budget < 0) {
        tw_json(['error' => 'zone_layout_too_large'], 422);
    }
    if (!is_array($node)) {
        tw_json(['error' => 'zone_layout_invalid'], 422);
    }

    // Split node: has children (and an axis).
    if (array_key_exists('children', $node) || array_key_exists('axis', $node)) {
        $children = $node['children'] ?? null;
        if (!is_array($children) || count($children) < 2) {
            tw_json(['error' => 'zone_layout_split_children'], 422);
        }
        $out = [];
        foreach ($children as $ch) {
            if (!is_array($ch) || !isset($ch['size']) || !is_numeric($ch['size'])
                || (float) $ch['size'] <= 0 || !array_key_exists('node', $ch)) {
                tw_json(['error' => 'zone_layout_child'], 422);
            }
            $out[] = [
                'size' => 0 + $ch['size'], // relative weight, normalized when resolved
                'node' => tw_zone_node($ch['node'], $pdo, $depth + 1, $budget),
            ];
        }
        return ['axis' => tw_zone_axis($node['axis'] ?? 'rows'), 'children' => $out];
    }

    // Leaf node: a zone bound to a source.
    $zone = $node['zone'] ?? null;
    if (is_array($zone)) {
        $src = $zone['source'] ?? null;
        if ($src === 'customer') {
            return ['zone' => ['source' => 'customer']];
        }
        if (is_numeric($src) && (int) $src > 0) {
            $pid = (int) $src;
            // Deliberately NOT tenant-restricted: like the company zone today, a
            // custom zone may carry our own presentation from another tenant.
            $cs = $pdo->prepare('SELECT id FROM presentations WHERE id = ?');
            $cs->execute([$pid]);
            if (!$cs->fetch()) {
                tw_json(['error' => 'zone_layout_presentation_not_found', 'id' => $pid], 422);
            }
            return ['zone' => ['source' => $pid]];
        }
        tw_json(['error' => 'zone_layout_source'], 422);
    }

    tw_json(['error' => 'zone_layout_invalid'], 422);
}

/**
 * Validate and canonicalize the whole custom zone layout for storage. Accepts a
 * decoded array or a JSON string; empty/absent clears it (returns null). Only the
 * four display formats are allowed as keys; each maps to one validated tree.
 */
function tw_zone_layout(mixed $v, PDO $pdo): ?string
{
    if ($v === null || $v === '' || $v === false) {
        return null;
    }
    $data = is_array($v) ? $v : json_decode((string) $v, true);
    if (!is_array($data) || ($data['v'] ?? null) !== 1
        || !isset($data['layouts']) || !is_array($data['layouts'])) {
        tw_json(['error' => 'zone_layout_invalid'], 422);
    }
    $budget = TW_ZONE_MAX_NODES;
    $layouts = [];
    foreach ($data['layouts'] as $fmt => $node) {
        if (!in_array($fmt, TW_DISPLAY_FORMATS, true)) {
            tw_json(['error' => 'zone_layout_format', 'format' => (string) $fmt], 422);
        }
        $layouts[$fmt] = tw_zone_node($node, $pdo, 1, $budget);
    }
    if (!$layouts) {
        return null; // no formats configured -> nothing custom to store
    }
    return json_encode(['v' => 1, 'layouts' => $layouts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function tw_gen_pairing(PDO $pdo): string
{
    for ($i = 0; $i < 25; $i++) {
        $hex = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars
        $code = substr($hex, 0, 3) . '-' . substr($hex, 3, 3);
        $s = $pdo->prepare('SELECT 1 FROM devices WHERE pairing_code = ?');
        $s->execute([$code]);
        if (!$s->fetch()) {
            return $code;
        }
    }
    throw new RuntimeException('could not generate a unique pairing code');
}

/** Tenant that owns $id, or null when the device does not exist. */
function tw_device_tenant(PDO $pdo, int $id): ?int
{
    $s = $pdo->prepare('SELECT tenant_id FROM devices WHERE id = ?');
    $s->execute([$id]);
    $v = $s->fetchColumn();
    return $v === false ? null : (int) $v;
}

if ($method === 'GET') {
    $tenantId = (int) ($_GET['tenant_id'] ?? 0);
    $sel = 'SELECT d.*, TIMESTAMPDIFF(SECOND, d.last_seen, NOW()) AS seconds_since_seen FROM devices d';
    if ($tenantId > 0) {
        tw_require_tenant($tenantId);
        $s = $pdo->prepare($sel . ' WHERE d.tenant_id = ? ORDER BY d.id');
        $s->execute([$tenantId]);
        $rows = $s->fetchAll();
    } else {
        [$scope, $args] = tw_tenant_filter('d.tenant_id');
        $s = $pdo->prepare($sel . " WHERE 1=1 $scope ORDER BY d.id");
        $s->execute($args);
        $rows = $s->fetchAll();
    }
    foreach ($rows as &$r) {
        $secs = $r['seconds_since_seen'] === null ? null : (int) $r['seconds_since_seen'];
        $r['seconds_since_seen'] = $secs;
        $r['status'] = tw_device_status($secs);
    }
    unset($r);
    tw_json(['devices' => $rows]);
}

if ($method === 'POST') {
    tw_require_staff(); // devices are provisioned by us, not by the customer
    $b = tw_body();
    $tenantId = (int) ($b['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        tw_json(['error' => 'tenant_id_required'], 422);
    }
    $code = trim((string) ($b['pairing_code'] ?? ''));
    if ($code === '') {
        $code = tw_gen_pairing($pdo);
    }
    $presId = !empty($b['presentation_id']) ? (int) $b['presentation_id'] : null;
    try {
        $pdo->prepare(
            'INSERT INTO devices (tenant_id, presentation_id, pairing_code, name, standort, projektnummer, anzeige_info, display_format)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([
            $tenantId,
            $presId,
            $code,
            (string) ($b['name'] ?? ''),
            (string) ($b['standort'] ?? ''),
            (string) ($b['projektnummer'] ?? ''),
            (string) ($b['anzeige_info'] ?? ''),
            tw_display_format($b['display_format'] ?? 'portrait'),
        ]);
    } catch (PDOException $e) {
        tw_json(['error' => 'pairing_taken'], 409);
    }
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO widget_settings (device_id) VALUES (?)')->execute([$id]);
    tw_json(['id' => $id, 'pairing_code' => $code], 201);
}

if ($method === 'PUT') {
    $b = tw_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $owner = tw_device_tenant($pdo, $id);
    if ($owner === null) {
        tw_json(['error' => 'not_found'], 404);
    }
    tw_require_tenant($owner);

    $set = [];
    $vals = [];

    // A customer may point their device at one of their own presentations, but
    // everything else about the device (naming, location, format, ownership) is
    // ours to set.
    if (array_key_exists('presentation_id', $b)) {
        $presId = !empty($b['presentation_id']) ? (int) $b['presentation_id'] : null;
        if ($presId !== null) {
            $ps = $pdo->prepare('SELECT tenant_id, is_company FROM presentations WHERE id = ?');
            $ps->execute([$presId]);
            $prow = $ps->fetch();
            // Never let a device show a presentation from a different tenant.
            if (!$prow || (int) $prow['tenant_id'] !== $owner) {
                tw_json(['error' => 'presentation_not_in_tenant'], 422);
            }
            // A customer may only assign their OWN (customer-side) presentations.
            if (tw_is_tenant_bound() && (int) ($prow['is_company'] ?? 0) === 1) {
                tw_json(['error' => 'presentation_not_yours'], 403);
            }
        }
        $set[] = 'presentation_id = ?';
        $vals[] = $presId;
    }

    if (!tw_is_tenant_bound()) {
        foreach (['name', 'standort', 'projektnummer', 'anzeige_info'] as $f) {
            if (array_key_exists($f, $b)) {
                $set[] = "$f = ?";
                $vals[] = (string) $b[$f];
            }
        }
        if (array_key_exists('tenant_id', $b)) {
            $set[] = 'tenant_id = ?';
            $vals[] = (int) $b['tenant_id'];
        }
        if (array_key_exists('display_format', $b)) {
            $set[] = 'display_format = ?';
            $vals[] = tw_display_format($b['display_format']);
        }
        // Zones are infrastructure: only we decide that a screen is split, how, and
        // what runs in the company half. The customer keeps presentation_id above.
        if (array_key_exists('zone_mode', $b)) {
            $set[] = 'zone_mode = ?';
            $vals[] = tw_zone_mode($b['zone_mode']);
        }
        if (array_key_exists('zone_axis', $b)) {
            $set[] = 'zone_axis = ?';
            $vals[] = tw_zone_axis($b['zone_axis']);
        }
        if (array_key_exists('zone_split', $b)) {
            $set[] = 'zone_split = ?';
            $vals[] = tw_zone_split($b['zone_split']);
        }
        // Position der Firmen-Zone: 1 = zuerst (oben/links), 0 = danach (unten/rechts).
        if (array_key_exists('zone_company_first', $b)) {
            $set[] = 'zone_company_first = ?';
            $vals[] = !empty($b['zone_company_first']) ? 1 : 0;
        }
        if (array_key_exists('company_presentation_id', $b)) {
            $cid = !empty($b['company_presentation_id']) ? (int) $b['company_presentation_id'] : null;
            // Deliberately NOT restricted to the device's tenant: the company zone
            // carries our own advertising presentation, which lives elsewhere.
            if ($cid !== null) {
                $cs = $pdo->prepare('SELECT id FROM presentations WHERE id = ?');
                $cs->execute([$cid]);
                if (!$cs->fetch()) {
                    tw_json(['error' => 'company_presentation_not_found'], 422);
                }
            }
            $set[] = 'company_presentation_id = ?';
            $vals[] = $cid;
        }
        // The free-form zone tree (zone_mode = 'custom'). Validated + canonicalized;
        // empty clears it. Legacy split fields above stay for backward compatibility.
        if (array_key_exists('zone_layout', $b)) {
            $set[] = 'zone_layout = ?';
            $vals[] = tw_zone_layout($b['zone_layout'], $pdo);
        }
    }

    if (!$set) {
        tw_json(['error' => 'nothing_to_update'], 422);
    }
    $vals[] = $id;
    $pdo->prepare('UPDATE devices SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($vals);
    tw_json(['id' => $id, 'updated' => true]);
}

if ($method === 'DELETE') {
    tw_require_staff();
    $id = (int) ($_GET['id'] ?? (tw_body()['id'] ?? 0));
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
    tw_json(['deleted' => $id]);
}

tw_json(['error' => 'method_not_allowed'], 405);
