<?php
/**
 * Playlist endpoint.
 *
 *  - GET playlist.php                     -> folder scan (backwards compatible):
 *        { "items": [ { name, hash, size }, ... ] }
 *  - GET playlist.php?device=<pairing>    -> device-specific, DB-backed:
 *        { "items": [ { name, hash, size, position, duration_ms }, ... ],
 *          "device": { pairing_code, name, standort, anzeige_info, display_format },
 *          "zones": null
 *                 | { mode:'split', axis:'rows'|'cols', split:<company %>,
 *                     company:[slides], customer:[slides] }
 *                 | { mode:'custom', v:1, tree:<Node> } where Node is a split
 *                   { axis:'rows'|'cols', children:[{ size:<num>, node:<Node> }] }
 *                   or a leaf { slides:[slides] } (sizes are relative weights),
 *          "tenant": { id, name },
 *          "widgets": { weather_enabled, weather_location, notices_enabled, notices_text, schedule } }
 *
 * `items` is always the flat union of every zone's slides: it is what the app
 * downloads and hash-compares. `zones` only says which of those files each zone
 * plays, in what order. In single mode `zones` is null and `items` is the whole
 * (and only) slideshow — the legacy contract, unchanged.
 *
 * Slides whose media file is missing on disk are skipped so the app's hash sync stays consistent.
 */
require __DIR__ . '/db.php';

$dir = __DIR__ . '/media';
$allowed = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];

/** Return [hash, size] for a media file, or null when it is not on disk. */
function tw_media_meta(string $dir, string $name): ?array
{
    $path = $dir . '/' . $name;
    if (!is_file($path)) {
        return null;
    }
    return ['hash' => hash_file('sha256', $path), 'size' => filesize($path)];
}

/**
 * The ordered slide list of one presentation. Media files missing on disk are
 * skipped so the app's hash sync stays consistent; weather slides are file-less.
 * $hasWeather is raised when the list contains a weather interstitial.
 */
function tw_slides_of(PDO $pdo, string $dir, ?int $presentationId, bool &$hasWeather): array
{
    if (empty($presentationId)) {
        return [];
    }
    // A switched-off presentation plays nothing where it is assigned.
    $act = $pdo->prepare('SELECT active FROM presentations WHERE id = ?');
    $act->execute([$presentationId]);
    $isActive = $act->fetchColumn();
    if ($isActive !== false && (int) $isActive === 0) {
        return [];
    }
    $ss = $pdo->prepare(
        'SELECT media_name, kind, text_title, text_body, text_font, text_color, text_size, position, duration_ms FROM slides
         WHERE presentation_id = ? ORDER BY position, id'
    );
    $ss->execute([$presentationId]);
    $out = [];
    foreach ($ss as $row) {
        $kind = (string) ($row['kind'] ?? 'media');
        if ($kind === 'weather') {
            $hasWeather = true;
            $out[] = [
                'name'        => '',
                'kind'        => 'weather',
                'position'    => (int) $row['position'],
                'duration_ms' => (int) $row['duration_ms'],
            ];
            continue;
        }
        // News: a file-less slide that carries its own message. It may name a
        // background image (a media-pool file) plus font/colour/size. The image
        // travels with hash+size so the device can pre-fetch it into its hidden
        // news-asset dir without the picture becoming a rotating slide.
        if ($kind === 'news') {
            $item = [
                'name'        => '',
                'kind'        => 'news',
                'title'       => (string) ($row['text_title'] ?? ''),
                'body'        => (string) ($row['text_body'] ?? ''),
                'font'        => (string) ($row['text_font'] ?? ''),
                'color'       => (string) ($row['text_color'] ?? ''),
                'size'        => (int) ($row['text_size'] ?? 0),
                'position'    => (int) $row['position'],
                'duration_ms' => (int) $row['duration_ms'],
            ];
            $bg = (string) ($row['media_name'] ?? '');
            if ($bg !== '') {
                $bgMeta = tw_media_meta($dir, $bg);
                if ($bgMeta !== null) {
                    $item['bg']      = $bg;
                    $item['bg_hash'] = $bgMeta['hash'];
                    $item['bg_size'] = $bgMeta['size'];
                }
            }
            $out[] = $item;
            continue;
        }
        $meta = tw_media_meta($dir, $row['media_name']);
        if ($meta === null) {
            continue;
        }
        $out[] = [
            'name'        => $row['media_name'],
            'kind'        => 'media',
            'hash'        => $meta['hash'],
            'size'        => $meta['size'],
            'position'    => (int) $row['position'],
            'duration_ms' => (int) $row['duration_ms'],
        ];
    }
    return $out;
}

/**
 * The app's download list: every real media file, each exactly once. File-less
 * weather/news slides carry no name and drop out (they live only inside `zones`).
 */
function tw_dedup_files(array $slides): array
{
    $items = [];
    $seen = [];
    foreach ($slides as $it) {
        $name = $it['name'] ?? '';
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $items[] = $it;
    }
    return $items;
}

/**
 * Resolve one node of a stored custom zone tree (Phase 5.3 Vollausbau) into its
 * playable form. A split mirrors its structure (axis + weighted children); a leaf
 * carries the ordered `slides` of its bound presentation — "customer" resolves to
 * the device's own presentation_id, a number to that presentation. Every real-file
 * slide is also appended to $flat (for the deduplicated download list) and
 * $hasWeather is raised via tw_slides_of when a leaf holds a weather interstitial.
 */
function tw_resolve_zone_node(
    PDO $pdo,
    string $dir,
    array $node,
    ?int $customerPresId,
    array &$flat,
    bool &$hasWeather
): array {
    if (isset($node['children']) && is_array($node['children'])) {
        $children = [];
        foreach ($node['children'] as $ch) {
            $sub = (isset($ch['node']) && is_array($ch['node'])) ? $ch['node'] : [];
            $children[] = [
                'size' => 0 + ($ch['size'] ?? 1),
                'node' => tw_resolve_zone_node($pdo, $dir, $sub, $customerPresId, $flat, $hasWeather),
            ];
        }
        return ['axis' => (string) ($node['axis'] ?? 'rows'), 'children' => $children];
    }
    // Leaf: a zone bound to a source.
    $src = $node['zone']['source'] ?? null;
    $presId = ($src === 'customer') ? $customerPresId : (is_numeric($src) ? (int) $src : null);
    $slides = tw_slides_of($pdo, $dir, $presId, $hasWeather);
    foreach ($slides as $s) {
        $flat[] = $s;
    }
    return ['slides' => $slides];
}

$device = isset($_GET['device']) ? trim((string) $_GET['device']) : '';

// --- Folder-scan fallback (no device): unchanged legacy contract. ---
if ($device === '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $items = [];
    if (is_dir($dir)) {
        foreach (scandir($dir) as $name) {
            $path = $dir . '/' . $name;
            if (!is_file($path)) {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                continue;
            }
            $items[] = [
                'name' => $name,
                'hash' => hash_file('sha256', $path),
                'size' => filesize($path),
            ];
        }
    }
    usort($items, static fn($a, $b) => strcasecmp($a['name'], $b['name']));
    echo json_encode(['items' => $items], JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Device-specific playlist. ---
try {
    $pdo = tw_db();
    $stmt = $pdo->prepare(
        'SELECT d.id, d.pairing_code, d.name, d.standort, d.anzeige_info, d.display_format, d.presentation_id,
                d.zone_mode, d.zone_axis, d.zone_split, d.company_presentation_id, d.zone_layout,
                t.id AS tenant_id, t.name AS tenant_name,
                t.contact_company AS tenant_company, t.contact_address AS tenant_address
         FROM devices d JOIN tenants t ON t.id = d.tenant_id
         WHERE d.pairing_code = ?'
    );
    $stmt->execute([$device]);
    $dev = $stmt->fetch();

    if (!$dev) {
        tw_json(['error' => 'unknown_device', 'items' => []], 404);
    }

    $pdo->prepare('UPDATE devices SET last_seen = NOW() WHERE id = ?')->execute([$dev['id']]);

    $hasWeather = false;
    $zoneMode = (string) ($dev['zone_mode'] ?? 'single');

    $customerPresId = !empty($dev['presentation_id']) ? (int) $dev['presentation_id'] : null;

    // Custom (Vollausbau): a free-form zone tree per display format. Resolve this
    // device's format; a format with no tree falls back to single (customer full
    // screen). $flat gathers every leaf's slides for the deduplicated download list.
    $customTree = null;
    $flat = [];
    if ($zoneMode === 'custom') {
        $parsed = is_string($dev['zone_layout'] ?? null) ? json_decode($dev['zone_layout'], true) : null;
        $fmt = $dev['display_format'] ?: 'portrait';
        if (is_array($parsed) && isset($parsed['layouts'][$fmt]) && is_array($parsed['layouts'][$fmt])) {
            $customTree = tw_resolve_zone_node($pdo, $dir, $parsed['layouts'][$fmt], $customerPresId, $flat, $hasWeather);
        } else {
            $zoneMode = 'single'; // no tree for this format -> customer full screen
        }
    }

    // The customer zone is the device's own presentation — in single mode it simply
    // is the whole screen, which keeps the legacy contract intact. Custom draws its
    // customer slides through the tree instead, so it needs no top-level list.
    $companyPresId = !empty($dev['company_presentation_id']) ? (int) $dev['company_presentation_id'] : null;

    // 'company' = whole screen shows the Teamwork/company presentation, no customer.
    // It behaves exactly like single (zones stay null), only the source differs.
    $customer = ($zoneMode === 'custom' || $zoneMode === 'company')
        ? [] : tw_slides_of($pdo, $dir, $customerPresId, $hasWeather);
    $company  = $zoneMode === 'split'
        ? tw_slides_of($pdo, $dir, $companyPresId, $hasWeather)
        : [];

    // `items` is the app's download list.
    //  - single/company: it is ALSO the whole playlist, so the file-less weather/news
    //    slides stay in it — the app builds its slideshow from exactly this array.
    //  - split/custom: the playlist lives in `zones`, so `items` carries only real
    //    files, each exactly once, even when several zones use the same one.
    if ($zoneMode === 'split') {
        $items = tw_dedup_files(array_merge($customer, $company));
    } elseif ($zoneMode === 'custom') {
        $items = tw_dedup_files($flat);
    } elseif ($zoneMode === 'company') {
        $items = tw_slides_of($pdo, $dir, $companyPresId, $hasWeather);
    } else {
        $items = $customer;
    }

    $zones = null;
    if ($zoneMode === 'split') {
        $zones = [
            'mode'    => 'split',
            'axis'    => (string) ($dev['zone_axis'] ?? 'rows'),
            'split'   => (int) ($dev['zone_split'] ?? 70),  // the company zone's share, %
            'company' => $company,
            'customer' => $customer,
        ];
    } elseif ($zoneMode === 'custom') {
        // The resolved tree: splits mirror axis + weighted children, leaves carry
        // `slides`. The app renders it recursively; sizes are relative weights.
        $zones = ['mode' => 'custom', 'v' => 1, 'tree' => $customTree];
    }

    $ws = $pdo->prepare(
        'SELECT weather_enabled, weather_location, notices_enabled, notices_text,
                notices_size, notices_bg, notices_height,
                notices_font, notices_color, notices_speed, schedule
         FROM widget_settings WHERE device_id = ?'
    );
    $ws->execute([$dev['id']]);
    $w = $ws->fetch() ?: [];

    // Global weather-interstitial template (shared). Delivered raw; the app renders it.
    // The background is a pool file downloaded separately from the slide set so it never
    // rotates as its own slide — only hinted when a weather slide is actually present.
    $weatherLayout = null;
    $weatherAsset = null;
    try {
        $lc = $pdo->query('SELECT config FROM weather_layout WHERE id = 1')->fetchColumn();
        $cfg = is_string($lc) ? json_decode($lc, true) : null;
        if (is_array($cfg)) {
            $weatherLayout = $cfg;
            $bg = is_string($cfg['background'] ?? null) ? $cfg['background'] : '';
            if ($hasWeather && $bg !== '' && strpbrk($bg, "/\\") === false && strpos($bg, '..') === false) {
                $meta = tw_media_meta($dir, $bg);
                if ($meta !== null) {
                    $weatherAsset = ['name' => $bg, 'hash' => $meta['hash'], 'size' => $meta['size']];
                }
            }
        }
    } catch (Throwable $e) {
        // weather_layout table may not exist yet (pre-migration): degrade silently.
    }

    // Central help/contact card (global settings); degrade silently pre-migration.
    $help = [
        'company' => '', 'app' => '', 'version' => '', 'phone' => '',
        'contact' => '', 'support_mail' => '', 'support_phone' => '', 'website' => '',
    ];
    try {
        $rows = tw_db()->query("SELECT k, v FROM app_settings WHERE k LIKE 'help\\_%'")->fetchAll();
        foreach ($rows as $r) {
            $field = substr((string) $r['k'], 5); // strip 'help_'
            if (array_key_exists($field, $help)) {
                $help[$field] = (string) $r['v'];
            }
        }
    } catch (Throwable $e) {
        // app_settings table may not exist yet.
    }

    tw_json([
        'items'  => $items,
        'help'   => $help,
        'device' => [
            'pairing_code'   => $dev['pairing_code'],
            'name'           => $dev['name'],
            'standort'       => $dev['standort'],
            'anzeige_info'   => $dev['anzeige_info'],
            'display_format' => $dev['display_format'] ?: 'portrait',
        ],
        'zones' => $zones,   // null in single mode — the app then plays `items` full-screen
        'tenant' => [
            'id'      => (int) $dev['tenant_id'],
            'name'    => $dev['tenant_name'],
            'company' => (string) ($dev['tenant_company'] ?? ''),
            'address' => (string) ($dev['tenant_address'] ?? ''),
        ],
        'widgets' => [
            'weather_enabled'  => (bool) ($w['weather_enabled'] ?? false),
            'weather_location' => (string) ($w['weather_location'] ?? ''),
            'notices_enabled'  => (bool) ($w['notices_enabled'] ?? false),
            'notices_text'     => (string) ($w['notices_text'] ?? ''),
            'notices_size'     => (int) ($w['notices_size'] ?? 15),
            'notices_bg'       => (string) ($w['notices_bg'] ?? '#66000000'),
            'notices_height'   => (int) ($w['notices_height'] ?? 0),
            'notices_font'     => (string) ($w['notices_font'] ?? ''),
            'notices_color'    => (string) ($w['notices_color'] ?? '#FFFFFFFF'),
            'notices_speed'    => (int) ($w['notices_speed'] ?? 90),
            'schedule'         => $w['schedule'] ?? null,
        ],
        'weather_layout' => $weatherLayout,
        'weather_asset'  => $weatherAsset,
    ]);
} catch (Throwable $e) {
    tw_json(['error' => 'server_error', 'items' => []], 500);
}
