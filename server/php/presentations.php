<?php
/**
 * Presentation CRUD incl. ordered slides with per-slide duration.
 *   GET  ?id=          -> { presentation: { ..., slides:[{id,media_name,position,duration_ms}] } }
 *   GET  ?tenant_id=   -> { presentations: [...] }
 *   POST {tenant_id, name}                       -> create
 *   PUT  {id, name?, slides?:[{media_name,kind,text_title?,text_body?,duration_ms,position?}]}
 *                      -> rename and/or replace the ordered slide list
 *   DELETE ?id=        -> delete (cascades slides)
 *
 * Slide kinds: 'media' (a pool file), 'weather' (file-less interstitial) and
 * 'news' (file-less message, carries its own title + body).
 */
require __DIR__ . '/auth.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

/** UTF-8-safe truncation to $n characters (no mbstring dependency). */
function tw_cut(string $s, int $n): string
{
    return preg_replace('/^(.{0,' . $n . '}).*$/us', '$1', $s) ?? $s;
}

/** Tenant that owns $id, or null when the presentation does not exist. */
function tw_presentation_tenant(PDO $pdo, int $id): ?int
{
    $s = $pdo->prepare('SELECT tenant_id FROM presentations WHERE id = ?');
    $s->execute([$id]);
    $v = $s->fetchColumn();
    return $v === false ? null : (int) $v;
}

if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id > 0) {
        $s = $pdo->prepare('SELECT * FROM presentations WHERE id = ?');
        $s->execute([$id]);
        $p = $s->fetch();
        if (!$p) {
            tw_json(['error' => 'not_found'], 404);
        }
        tw_require_tenant((int) $p['tenant_id']);
        $ss = $pdo->prepare('SELECT id, media_name, kind, text_title, text_body, text_font, text_color, text_size, position, duration_ms FROM slides WHERE presentation_id = ? ORDER BY position, id');
        $ss->execute([$id]);
        $p['slides'] = $ss->fetchAll();
        tw_json(['presentation' => $p]);
    }
    // first_media: the first media slide (lowest position) — the dashboard shows
    // it as a per-presentation thumbnail in the list.
    $firstMedia = "(SELECT s.media_name FROM slides s
                     WHERE s.presentation_id = p.id AND s.kind = 'media' AND s.media_name <> ''
                     ORDER BY s.position, s.id LIMIT 1) AS first_media";
    $tenantId = (int) ($_GET['tenant_id'] ?? 0);
    if ($tenantId > 0) {
        tw_require_tenant($tenantId);
        $s = $pdo->prepare("SELECT p.*, $firstMedia FROM presentations p WHERE p.tenant_id = ? ORDER BY p.id");
        $s->execute([$tenantId]);
        $rows = $s->fetchAll();
    } else {
        [$scope, $args] = tw_tenant_filter('p.tenant_id');
        $s = $pdo->prepare("SELECT p.*, $firstMedia FROM presentations p WHERE 1=1 $scope ORDER BY p.id");
        $s->execute($args);
        $rows = $s->fetchAll();
    }
    tw_json(['presentations' => $rows]);
}

if ($method === 'POST') {
    $b = tw_body();
    // A customer creates only inside their own tenant, whatever the body claims.
    $tenantId = (int) tw_owning_tenant($b['tenant_id'] ?? 0);
    $name = trim((string) ($b['name'] ?? ''));
    if ($tenantId <= 0 || $name === '') {
        tw_json(['error' => 'tenant_id_and_name_required'], 422);
    }
    $pdo->prepare('INSERT INTO presentations (tenant_id, name) VALUES (?, ?)')->execute([$tenantId, $name]);
    tw_json(['id' => (int) $pdo->lastInsertId(), 'name' => $name], 201);
}

if ($method === 'PUT') {
    $b = tw_body();
    $id = (int) ($b['id'] ?? 0);
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $owner = tw_presentation_tenant($pdo, $id);
    if ($owner === null) {
        tw_json(['error' => 'not_found'], 404);
    }
    tw_require_tenant($owner);
    if (array_key_exists('name', $b)) {
        $newName = trim((string) $b['name']);
        if ($newName === '') {
            tw_json(['error' => 'name_required'], 422);
        }
        $pdo->prepare('UPDATE presentations SET name = ? WHERE id = ?')->execute([$newName, $id]);
    }
    // Short list description (empty allowed = remove). Column via migrate_pres_description.php.
    if (array_key_exists('description', $b)) {
        $desc = tw_cut(trim((string) $b['description']), 300);
        $pdo->prepare('UPDATE presentations SET description = ? WHERE id = ?')->execute([$desc, $id]);
    }
    // Per-presentation on/off. It ONLY flips this presentation's own flag — it does
    // not touch any device assignment (each screen keeps what it is set to). An
    // inactive presentation simply stops playing where it is assigned.
    if (array_key_exists('active', $b)) {
        $pdo->prepare('UPDATE presentations SET active = ? WHERE id = ?')
            ->execute([$b['active'] ? 1 : 0, $id]);
    }
    if (array_key_exists('slides', $b) && is_array($b['slides'])) {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM slides WHERE presentation_id = ?')->execute([$id]);
        $ins = $pdo->prepare(
            'INSERT INTO slides (presentation_id, media_name, kind, text_title, text_body, text_font, text_color, text_size, position, duration_ms)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $pos = 0;
        foreach ($b['slides'] as $sl) {
            $kind = (string) ($sl['kind'] ?? 'media');
            if (!in_array($kind, ['media', 'weather', 'news'], true)) {
                $kind = 'media';
            }
            $mn = trim((string) ($sl['media_name'] ?? ''));
            $title = '';
            $body = null;
            $font = '';
            $color = '';
            $size = 0;
            if ($kind === 'news') {
                // A news slide carries its own text (title + body) and, optionally,
                // a background image (stored in media_name) plus font/colour/size.
                // An empty message would show an empty board, so drop it.
                $title = tw_cut(trim((string) ($sl['text_title'] ?? '')), 200);
                $body = tw_cut(trim((string) ($sl['text_body'] ?? '')), 2000);
                if ($title === '' && $body === '') {
                    continue;
                }
                // Background image must be a bare file name (no path traversal).
                if ($mn !== '' && (strpbrk($mn, "/\\") !== false || strpos($mn, '..') !== false)) {
                    $mn = '';
                }
                $font = tw_cut(trim((string) ($sl['text_font'] ?? '')), 40);
                $c = strtoupper(trim((string) ($sl['text_color'] ?? '')));
                $color = preg_match('/^#[0-9A-F]{6}([0-9A-F]{2})?$/', $c) ? $c : '';
                $size = max(0, min(200, (int) ($sl['text_size'] ?? 0)));
            } elseif ($kind === 'weather') {
                $mn = ''; // file-less interstitial
            } elseif ($mn === '') {
                continue; // a media slide without a file is nothing
            }
            $dur = (int) ($sl['duration_ms'] ?? 8000);
            if ($dur < 250) {
                $dur = 250;
            }
            $position = array_key_exists('position', $sl) ? (int) $sl['position'] : $pos;
            $ins->execute([$id, $mn, $kind, $title, $body, $font, $color, $size, $position, $dur]);
            $pos++;
        }
        $pdo->commit();
    }
    tw_json(['id' => $id, 'updated' => true]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? (tw_body()['id'] ?? 0));
    if ($id <= 0) {
        tw_json(['error' => 'id_required'], 422);
    }
    $owner = tw_presentation_tenant($pdo, $id);
    if ($owner === null) {
        tw_json(['error' => 'not_found'], 404);
    }
    tw_require_tenant($owner);
    $pdo->prepare('DELETE FROM presentations WHERE id = ?')->execute([$id]);
    tw_json(['deleted' => $id]);
}

tw_json(['error' => 'method_not_allowed'], 405);
