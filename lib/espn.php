<?php
/**
 * NBA desde ESPN (API pública no oficial, sin clave).
 * - espn_nba_events(): partidos de la temporada en curso (calendario + resultados + escudos).
 * - nba_players_update(): estadísticas de cada jugador partido a partido -> public/data/nba.json
 * Si ESPN cambia algo y falla, se conserva la última copia y el resto de la web sigue igual.
 */

function espn_base(): string
{
    return getenv('ESPN_BASE') ?: 'https://site.api.espn.com/apis/site/v2/sports/basketball/nba';
}

function espn_fetch(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; UmbralAnalytics/1.0)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) return null;
    $j = json_decode($body, true);
    return is_array($j) ? $j : null;
}

/** Año de inicio de la temporada en curso (la 2026-27 es 2026). */
function nba_season_year(int $now): int
{
    $y = (int)gmdate('Y', $now);
    return ((int)gmdate('n', $now) >= 9) ? $y : $y - 1;
}

function espn_nba_events(array $cfg, array $league): array
{
    static $memo = null;
    if ($memo !== null) return $memo;
    $root = dirname(__DIR__);
    $now = (int)($GLOBALS['UMBRAL_NOW'] ?? time());
    $season = nba_season_year($now);
    $cacheDir = defined('UMBRAL_CACHE') ? UMBRAL_CACHE : "$root/data/cache";
    if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
    $cachePath = "$cacheDir/basquet-espn-$season.json";
    $old = fn() => is_file($cachePath) ? (json_decode(file_get_contents($cachePath), true) ?: []) : [];
    if (is_file($cachePath) && filemtime($cachePath) > time() - $cfg['cache_minutes'] * 60) return $memo = $old();

    $from = gmmktime(12, 0, 0, 10, 1, $season);
    $to = $now + (($cfg['days_ahead'] ?? 3) + 2) * 86400;
    $all = []; $chunks = 0;
    for ($d = $from; $d <= $to; $d += 14 * 86400) {
        $e = min($to, $d + 13 * 86400);
        $url = espn_base() . '/scoreboard?dates=' . gmdate('Ymd', $d) . '-' . gmdate('Ymd', $e) . '&limit=1000';
        $j = espn_fetch($url);
        if ($j === null) {
            $GLOBALS['UMBRAL_ERRORS'] = ($GLOBALS['UMBRAL_ERRORS'] ?? 0) + 1;
            log_line("ERROR basquet/NBA (ESPN): no respondió el calendario " . gmdate('Y-m-d', $d));
            return $memo = $old();
        }
        foreach ($j['events'] ?? [] as $ev) {
            $type = (int)($ev['season']['type'] ?? 2);
            if ($type === 1 || $type === 4) continue; // pretemporada / fuera de temporada
            $all[(string)$ev['id']] = $ev;
        }
        $chunks++;
        usleep(300000);
    }
    $all = array_values($all);
    file_put_contents($cachePath, json_encode($all));
    log_line('OK basquet/NBA (ESPN): ' . count($all) . " partidos (temporada $season, $chunks consultas)");
    return $memo = $all;
}

/** Convierte un partido de ESPN al formato común. */
function espn_normalize(array $ev, string $leagueName): ?array
{
    $c = $ev['competitions'][0] ?? null;
    if (!$c) return null;
    $home = $away = null;
    foreach ($c['competitors'] ?? [] as $t) {
        if (($t['homeAway'] ?? '') === 'home') $home = $t; else $away = $t;
    }
    if (!$home || !$away) return null;
    $st = $c['status']['type'] ?? $ev['status']['type'] ?? [];
    $name = $st['name'] ?? '';
    $state = $st['state'] ?? 'pre';
    if (!empty($st['completed']) && $state === 'post' && $name !== 'STATUS_CANCELED' && $name !== 'STATUS_POSTPONED') $status = 'FT';
    elseif ($name === 'STATUS_POSTPONED') $status = 'PST';
    elseif ($name === 'STATUS_CANCELED') $status = 'CANC';
    elseif ($state === 'in') $status = 'LIVE';
    else $status = 'NS';
    $done = $status === 'FT';
    $tn = fn($t) => $t['team']['displayName'] ?? $t['team']['name'] ?? '?';
    $lg = fn($t) => $t['team']['logo'] ?? ($t['team']['logos'][0]['href'] ?? '');
    return [
        'id' => 'basquet-espn-' . $ev['id'],
        'ts' => (int)strtotime($ev['date'] ?? $c['date'] ?? ''),
        'status' => $status,
        'league' => $leagueName,
        'home' => $tn($home), 'away' => $tn($away),
        'home_id' => (int)($home['team']['id'] ?? $home['id'] ?? 0), 'away_id' => (int)($away['team']['id'] ?? $away['id'] ?? 0),
        'logo_home' => $lg($home), 'logo_away' => $lg($away),
        'hs' => $done ? (int)$home['score'] : null, 'as' => $done ? (int)$away['score'] : null,
    ];
}

/** Busca la columna de una estadística por su clave o su etiqueta. */
function espn_col(array $keys, array $labels, array $want): ?int
{
    foreach ($want as $w) {
        $i = array_search($w, $keys, true);
        if ($i !== false) return $i;
        $i = array_search($w, $labels, true);
        if ($i !== false) return $i;
    }
    return null;
}

/**
 * Descarga la planilla (box score) de los partidos terminados que aún no tiene y
 * guarda las estadísticas de cada jugador en $dataDir/nba.json.
 */
function nba_players_update(array $cfg, array $events, string $dataDir): void
{
    $now = (int)($GLOBALS['UMBRAL_NOW'] ?? time());
    $season = nba_season_year($now);
    $path = "$dataDir/nba.json";
    $db = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    if (($db['season'] ?? null) !== $season) $db = ['season' => $season, 'teams' => [], 'players' => [], 'done' => []];
    $done = array_flip(array_map('strval', $db['done'] ?? []));

    // Equipos (nombre, sigla y escudo) desde el calendario
    foreach ($events as $ev) {
        foreach ($ev['competitions'][0]['competitors'] ?? [] as $t) {
            $id = (string)($t['team']['id'] ?? '');
            if ($id === '') continue;
            $db['teams'][$id] = [
                'n' => $t['team']['displayName'] ?? '?', 'ab' => $t['team']['abbreviation'] ?? '',
                'logo' => $t['team']['logo'] ?? ($db['teams'][$id]['logo'] ?? ''),
            ];
        }
    }

    $pending = [];
    foreach ($events as $ev) {
        $n = espn_normalize($ev, 'NBA');
        if ($n && $n['status'] === 'FT' && !isset($done[(string)$ev['id']])) $pending[] = [$ev['id'], $n];
    }
    usort($pending, fn($a, $b) => $a[1]['ts'] <=> $b[1]['ts']);
    $max = (int)(getenv('NBA_MAX_BOX') ?: 150);
    $pending = array_slice($pending, 0, $max);

    $ok = 0; $fail = 0;
    foreach ($pending as [$eid, $g]) {
        $j = espn_fetch(espn_base() . '/summary?event=' . urlencode((string)$eid));
        usleep((int)(getenv('ESPN_PAUSE_MS') ?: 400) * 1000);
        $teamsBox = $j['boxscore']['players'] ?? null;
        if (!$teamsBox) { $fail++; if ($fail >= 8) break; continue; }
        foreach ($teamsBox as $tb) {
            $tid = (int)($tb['team']['id'] ?? 0);
            $isHome = $tid === $g['home_id'];
            $opp = $isHome ? $g['away_id'] : $g['home_id'];
            $st = $tb['statistics'][0] ?? [];
            $keys = $st['keys'] ?? $st['names'] ?? [];
            $labels = $st['labels'] ?? $st['names'] ?? [];
            $cMin = espn_col($keys, $labels, ['minutes', 'MIN']);
            $cPts = espn_col($keys, $labels, ['points', 'PTS']);
            $cReb = espn_col($keys, $labels, ['rebounds', 'REB']);
            $cAst = espn_col($keys, $labels, ['assists', 'AST']);
            $c3 = espn_col($keys, $labels, ['threePointFieldGoalsMade-threePointFieldGoalsAttempted', '3PT']);
            $cStl = espn_col($keys, $labels, ['steals', 'STL']);
            $cBlk = espn_col($keys, $labels, ['blocks', 'BLK']);
            if ($cPts === null) continue;
            foreach ($st['athletes'] ?? [] as $a) {
                $s = $a['stats'] ?? [];
                if (!empty($a['didNotPlay']) || !$s) continue;
                $num = fn($i) => ($i === null || !isset($s[$i])) ? 0 : (int)explode('-', (string)$s[$i])[0];
                $min = $num($cMin);
                if ($min <= 0 && $num($cPts) === 0) continue;
                $ath = $a['athlete'] ?? [];
                $pid = (string)($ath['id'] ?? '');
                if ($pid === '') continue;
                $p = $db['players'][$pid] ?? ['h' => []];
                $p['n'] = $ath['displayName'] ?? ($p['n'] ?? '?');
                $p['ph'] = $ath['headshot']['href'] ?? ($p['ph'] ?? '');
                $p['pos'] = $ath['position']['abbreviation'] ?? ($p['pos'] ?? '');
                $p['t'] = $tid; // equipo actual (cambia si lo traspasan)
                // [fecha, rival, local, min, pts, reb, ast, triples, robos, tapas]
                $p['h'][] = [$g['ts'], $opp, $isHome ? 1 : 0, $min, $num($cPts), $num($cReb), $num($cAst), $num($c3), $num($cStl), $num($cBlk)];
                $db['players'][$pid] = $p;
            }
        }
        $done[(string)$eid] = 1;
        $ok++;
    }
    foreach ($db['players'] as &$p) { usort($p['h'], fn($a, $b) => $a[0] <=> $b[0]); }
    unset($p);
    $db['done'] = array_map('intval', array_keys($done));
    $db['updated'] = $now;
    file_put_contents("$path.tmp", json_encode($db, JSON_UNESCAPED_UNICODE));
    rename("$path.tmp", $path);
    log_line("OK jugadores NBA: $ok planillas nuevas" . ($fail ? ", $fail sin datos" : '') . ', ' . count($db['players']) . ' jugadores');
}
