<?php
/**
 * Global weather-interstitial template (single shared config, row id = 1).
 *   GET -> { config: {...} }   (defaults merged when the row is empty)
 *   PUT { config: {...} }      -> validated + stored, returns { config: {...} }
 *
 * The config drives the app's weather slide: background (pool media_name), scrim,
 * and per-element grid position/size for city / forecast / clock plus free texts.
 */
require __DIR__ . '/auth.php';
tw_require_manage();

$pdo = tw_db();
$method = $_SERVER['REQUEST_METHOD'];

const TW_WX_H = ['left', 'center', 'right'];
// Vertical placement is a fixed row: Header, rows 1-6, Footer (equal bands, top -> bottom).
const TW_WX_V = ['header', '1', '2', '3', '4', '5', '6', 'footer'];

function tw_wx_defaults(): array
{
    return [
        'background' => '',
        'scrim'      => 20,
        'city'       => ['show' => true, 'h' => 'center', 'v' => 'header', 'size' => 34, 'color' => '#FFFFFF'],
        'forecast'   => ['show' => true, 'h' => 'center', 'v' => '4',      'size' => 100],
        'clock'      => ['show' => true, 'h' => 'right',  'v' => '5',      'size' => 150],
        'texts'      => [],
    ];
}

function tw_wx_clamp($v, int $min, int $max, int $fallback): int
{
    $n = is_numeric($v) ? (int) $v : $fallback;
    return max($min, min($max, $n));
}

function tw_wx_h($v): string
{
    return in_array($v, TW_WX_H, true) ? $v : 'center';
}

function tw_wx_v($v): string
{
    // Accept the row set, and map the legacy top/middle/bottom bands onto rows.
    $s = is_scalar($v) ? (string) $v : '';
    $legacy = ['top' => 'header', 'middle' => '4', 'bottom' => 'footer'];
    $s = $legacy[$s] ?? $s;
    return in_array($s, TW_WX_V, true) ? $s : 'header';
}

/** Accept #rgb / #rrggbb, else white. */
function tw_wx_color($v): string
{
    $s = is_string($v) ? trim($v) : '';
    return preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $s) ? strtoupper($s) : '#FFFFFF';
}

/** UTF-8-safe truncation to $n characters (no mbstring dependency). */
function tw_wx_cut(string $s, int $n): string
{
    return preg_replace('/^(.{0,' . $n . '}).*$/us', '$1', $s) ?? $s;
}

/** Whitelist + clamp an incoming config into the canonical shape. */
function tw_wx_normalize(array $in): array
{
    $d = tw_wx_defaults();

    $bg = is_string($in['background'] ?? null) ? trim($in['background']) : '';
    // A background is a bare pool file name; never a path.
    if (strpbrk($bg, "/\\") !== false || strpos($bg, '..') !== false) {
        $bg = '';
    }
    $bg = tw_wx_cut($bg, 255);

    $elem = function (array $src, array $def, int $sizeMin, int $sizeMax, bool $color) {
        return array_filter([
            'show'  => (bool) ($src['show'] ?? $def['show']),
            'h'     => tw_wx_h($src['h'] ?? $def['h']),
            'v'     => tw_wx_v($src['v'] ?? $def['v']),
            'size'  => tw_wx_clamp($src['size'] ?? $def['size'], $sizeMin, $sizeMax, $def['size']),
            'color' => $color ? tw_wx_color($src['color'] ?? '#FFFFFF') : null,
        ], static fn($x) => $x !== null);
    };

    $texts = [];
    foreach ((array) ($in['texts'] ?? []) as $t) {
        if (!is_array($t)) {
            continue;
        }
        $txt = is_string($t['text'] ?? null) ? trim($t['text']) : '';
        if ($txt === '') {
            continue;
        }
        $texts[] = [
            'text'  => tw_wx_cut($txt, 200),
            'h'     => tw_wx_h($t['h'] ?? 'center'),
            'v'     => tw_wx_v($t['v'] ?? 'bottom'),
            'size'  => tw_wx_clamp($t['size'] ?? 20, 8, 200, 20),
            'color' => tw_wx_color($t['color'] ?? '#FFFFFF'),
        ];
        if (count($texts) >= 5) {
            break;
        }
    }

    return [
        'background' => $bg,
        'scrim'      => tw_wx_clamp($in['scrim'] ?? $d['scrim'], 0, 100, $d['scrim']),
        'city'       => $elem((array) ($in['city'] ?? []), $d['city'], 8, 200, true),
        'forecast'   => $elem((array) ($in['forecast'] ?? []), $d['forecast'], 20, 300, false),
        'clock'      => $elem((array) ($in['clock'] ?? []), $d['clock'], 40, 600, false),
        'texts'      => $texts,
    ];
}

/** Read + normalise the stored config (defaults when missing/invalid). */
function tw_wx_load(PDO $pdo): array
{
    $raw = $pdo->query('SELECT config FROM weather_layout WHERE id = 1')->fetchColumn();
    $cfg = is_string($raw) ? json_decode($raw, true) : null;
    return tw_wx_normalize(is_array($cfg) ? $cfg : []);
}

if ($method === 'GET') {
    tw_json(['config' => tw_wx_load($pdo)]);
}

if ($method === 'PUT') {
    $b = tw_body();
    $config = is_array($b['config'] ?? null) ? $b['config'] : [];
    $norm = tw_wx_normalize($config);
    $json = json_encode($norm, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $pdo->prepare(
        'INSERT INTO weather_layout (id, config) VALUES (1, ?)
         ON DUPLICATE KEY UPDATE config = VALUES(config)'
    )->execute([$json]);
    tw_json(['config' => $norm, 'updated' => true]);
}

tw_json(['error' => 'method_not_allowed'], 405);
