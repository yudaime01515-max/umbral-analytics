<?php
/**
 * Ligas de fútbol extra desde ESPN (gratis, sin clave): Liga 1 Perú, Argentina, Brasil, México, etc.
 * Guarda los partidos de cada liga en public/data/ligas/<CODIGO>.json para no pedir días viejos otra vez.
 * Devuelve los partidos ya en el formato común (como normalize()).
 */

/** Convierte un partido de fútbol de ESPN al formato común. */
function espn_soccer_norm(array $ev, string $leagueName, string $sportKey = 'futbol'): ?array
{
    $soccer = $sportKey === 'futbol';
    // Pretemporada de las ligas de EE. UU.: no cuenta para medir a los equipos
    if (!$soccer && (int)($ev['season']['type'] ?? 2) === 1) return null;
    $c = $ev['competitions'][0] ?? null;
    if (!$c) return null;
    $home = $away = null;
    foreach ($c['competitors'] ?? [] as $t) { if (($t['homeAway'] ?? '') === 'home') $home = $t; else $away = $t; }
    if (!$home || !$away) return null;
    $st = $c['status']['type'] ?? $ev['status']['type'] ?? [];
    $name = strtoupper($st['name'] ?? ''); $state = $st['state'] ?? 'pre';
    if (strpos($name, 'POSTPON') !== false) $status = 'PST';
    elseif (strpos($name, 'CANCEL') !== false) $status = 'CANC';
    elseif (strpos($name, 'ABANDON') !== false || strpos($name, 'SUSPEND') !== false) $status = 'ABD';
    elseif ($state === 'post' || !empty($st['completed'])) {
        // Con prórroga o penales el marcador ya no es el de los 90 minutos: no se usa para cerrar apuestas
        // (en NFL y NHL la prórroga sí cuenta para el ganador y el total, como en las casas)
        $status = ($soccer && (strpos($name, 'AET') !== false || strpos($name, 'PEN') !== false || strpos($name, 'EXTRA') !== false)) ? 'XET' : 'FT';
    }
    elseif ($state === 'in') $status = 'LIVE';
    else $status = 'NS';
    $done = $status === 'FT';
    $tn = fn($t) => $t['team']['displayName'] ?? $t['team']['name'] ?? '?';
    $sn = fn($t) => $t['team']['shortDisplayName'] ?? $t['team']['displayName'] ?? '?';
    $lg = fn($t) => $t['team']['logo'] ?? ($t['team']['logos'][0]['href'] ?? '');
    return [
        'id' => $sportKey . '-espn-' . $ev['id'], 'ts' => (int)strtotime($ev['date'] ?? $c['date'] ?? ''), 'status' => $status,
        'league' => $leagueName, 'home' => $soccer ? $sn($home) : $tn($home), 'away' => $soccer ? $sn($away) : $tn($away), 'home_full' => $tn($home), 'away_full' => $tn($away),
        'home_id' => (string)($home['team']['id'] ?? $home['id'] ?? ''), 'away_id' => (string)($away['team']['id'] ?? $away['id'] ?? ''),
        'logo_home' => $lg($home), 'logo_away' => $lg($away),
        'hs' => $done ? (int)$home['score'] : null, 'as' => $done ? (int)$away['score'] : null,
    ];
}

/** Partidos de un día de una liga que no es de fútbol (NFL, NHL...). 'path' = deporte/liga en ESPN. */
function espn_path_day(string $path, string $ymd): ?array
{
    $b = (getenv('ESPN_SPORTS_BASE') ?: 'https://site.api.espn.com/apis/site/v2/sports') . "/$path/scoreboard?dates=$ymd";
    $j = espn_fetch($b);
    if ($j === null) { usleep(500000); $j = espn_fetch($b); }
    return $j === null ? null : ($j['events'] ?? []);
}

function espn_league_games(array $cfg, array $league, string $sportKey = 'futbol'): array
{
    $now = (int)($GLOBALS['UMBRAL_NOW'] ?? time());
    $dir = (defined('UMBRAL_DATA') ? UMBRAL_DATA : dirname(__DIR__) . '/public/data') . '/ligas';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $code = $league['code']; $slug = $league['slug'] ?? '';
    $path = "$dir/$code.json";
    $db = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    $db += ['days' => [], 'ev' => []];
    $done = array_flip($db['days']);
    $today = strtotime(gmdate('Y-m-d', $now) . ' 00:00 UTC');

    $fresh = []; for ($d = $today - 3 * 86400; $d <= $now + (($cfg['days_ahead'] ?? 3) + 1) * 86400; $d += 86400) $fresh[] = gmdate('Ymd', $d);
    $old = []; for ($d = $today - 4 * 86400; $d >= $now - 150 * 86400; $d -= 86400) { $k = gmdate('Ymd', $d); if (!isset($done[$k])) $old[] = $k; }
    $list = array_merge($fresh, array_slice($old, 0, (int)(getenv('LIGAS_MAX_DAYS') ?: 40)));
    $fails = 0; $read = 0;
    foreach ($list as $ymd) {
        $evs = !empty($league['path']) ? espn_path_day($league['path'], $ymd) : espn_soccer_day($slug, $ymd);
        usleep((int)(getenv('ESPN_PAUSE_MS') ?: 250) * 1000);
        if ($evs === null) { if (++$fails >= 4) break; continue; }
        $read++;
        foreach ($evs as $ev) { $n = espn_soccer_norm($ev, $league['name'], $sportKey); if ($n) $db['ev'][$n['id']] = $n; }
        if (!in_array($ymd, $fresh, true)) $done[$ymd] = 1;
    }
    $db['ev'] = array_filter($db['ev'], fn($g) => $g['ts'] > $now - 200 * 86400);
    $db['days'] = array_map('strval', array_keys($done));
    file_put_contents("$path.tmp", json_encode($db, JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);
    if (!$read) {
        $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
        log_line("ERROR $sportKey/{$league['name']} (ESPN): " . ($GLOBALS['ESPN_LAST'] ?? 'sin respuesta'));
    } else log_line("OK $sportKey/{$league['name']} (ESPN): " . count($db['ev']) . " partidos ($read días leídos)");
    return array_values($db['ev']);
}
