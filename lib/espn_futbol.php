<?php
/**
 * Jugadores de fútbol desde ESPN (API pública no oficial, sin clave).
 * Por cada liga guarda public/data/fut/<CODIGO>.json con las estadísticas
 * partido a partido de cada jugador (goles, asistencias, tiros, tarjetas...).
 * Si ESPN falla, se conserva lo anterior y el resto de la web sigue igual.
 */

const ESPN_SOCCER_SLUGS = [
    'PL' => 'eng.1', 'PD' => 'esp.1', 'SA' => 'ita.1', 'BL1' => 'ger.1', 'FL1' => 'fra.1',
    'CL' => 'uefa.champions', 'PPL' => 'por.1', 'DED' => 'ned.1',
];

function espn_soccer_base(string $slug): string
{
    $b = getenv('ESPN_SOCCER_BASE') ?: 'https://site.api.espn.com/apis/site/v2/sports/soccer';
    return "$b/$slug";
}

/** Calendario de la temporada (desde julio) de una liga. */
function espn_soccer_events(string $slug, int $now): ?array
{
    $y = (int)gmdate('Y', $now);
    $season = ((int)gmdate('n', $now) >= 7) ? $y : $y - 1;
    $from = gmmktime(12, 0, 0, 7, 1, $season);
    $all = [];
    for ($d = $from; $d <= $now; $d += 21 * 86400) {
        $e = min($now, $d + 20 * 86400);
        $range = gmdate('Ymd', $d) . '-' . gmdate('Ymd', $e);
        $j = espn_fetch(espn_soccer_base($slug) . "/scoreboard?dates=$range&limit=500");
        if ($j === null) {
            usleep(400000);
            $j = espn_fetch(espn_soccer_base($slug) . "/scoreboard?dates=$range");
        }
        if ($j === null) return null;
        foreach ($j['events'] ?? [] as $ev) $all[(string)$ev['id']] = $ev;
        usleep(250000);
    }
    return array_values($all);
}

/** Valor de una estadística de la lista de ESPN [{name, value}, ...]. */
function espn_stat(array $stats, array $names): int
{
    foreach ($stats as $s) {
        if (in_array($s['name'] ?? $s['abbreviation'] ?? '', $names, true)) return (int)round((float)($s['value'] ?? $s['displayValue'] ?? 0));
    }
    return 0;
}

/**
 * Actualiza los archivos de jugadores de todas las ligas.
 * Devuelve [codigo => [espnTeamId => nombre]] para enlazar equipos con los partidos.
 */
function futbol_players_update(array $cfg, array $codes, string $dataDir): array
{
    $now = (int)($GLOBALS['UMBRAL_NOW'] ?? time());
    $dir = "$dataDir/fut";
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $budget = (int)(getenv('FUT_MAX_BOX') ?: 260); // planillas por ejecución (en total)
    $teamsOut = [];
    $logs = [];

    $codes = array_values($codes);
    foreach ($codes as $li => $code) {
        $slug = ESPN_SOCCER_SLUGS[$code] ?? null;
        // reparte las planillas entre las ligas que faltan, para que todas avancen a la vez
        $share = (int)ceil($budget / max(1, count($codes) - $li));
        if (!$slug) continue;
        $path = "$dir/$code.json";
        $db = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        $y = (int)gmdate('Y', $now);
        $season = ((int)gmdate('n', $now) >= 7) ? $y : $y - 1;
        if (($db['season'] ?? null) !== $season) $db = ['season' => $season, 'teams' => [], 'players' => [], 'done' => []];
        $done = array_flip(array_map('strval', $db['done'] ?? []));

        $events = espn_soccer_events($slug, $now);
        if ($events === null) {
            log_line("ERROR jugadores $code (ESPN): " . ($GLOBALS['ESPN_LAST'] ?? 'sin respuesta'));
            $teamsOut[$code] = array_map(fn($t) => $t['n'], $db['teams']);
            continue;
        }

        $pending = [];
        foreach ($events as $ev) {
            $c = $ev['competitions'][0] ?? [];
            foreach ($c['competitors'] ?? [] as $t) {
                $id = (string)($t['team']['id'] ?? $t['id'] ?? '');
                if ($id === '') continue;
                $db['teams'][$id] = [
                    'n' => $t['team']['displayName'] ?? $t['team']['name'] ?? '?',
                    'ab' => $t['team']['abbreviation'] ?? '',
                    'logo' => $t['team']['logo'] ?? ($db['teams'][$id]['logo'] ?? ''),
                ];
            }
            $st = $c['status']['type'] ?? $ev['status']['type'] ?? [];
            if (!empty($st['completed']) && ($st['state'] ?? '') === 'post' && !isset($done[(string)$ev['id']])) {
                $home = $away = null;
                foreach ($c['competitors'] ?? [] as $t) { if (($t['homeAway'] ?? '') === 'home') $home = $t; else $away = $t; }
                if ($home && $away) $pending[] = ['id' => (string)$ev['id'], 'ts' => (int)strtotime($ev['date'] ?? ''),
                    'h' => (string)($home['team']['id'] ?? $home['id']), 'a' => (string)($away['team']['id'] ?? $away['id'])];
            }
        }
        usort($pending, fn($a, $b) => $a['ts'] <=> $b['ts']);

        $ok = 0; $fail = 0;
        foreach ($pending as $g) {
            if ($budget <= 0 || $share <= 0) break;
            $budget--; $share--;
            $j = espn_fetch(espn_soccer_base($slug) . '/summary?event=' . urlencode($g['id']));
            usleep((int)(getenv('ESPN_PAUSE_MS') ?: 350) * 1000);
            $rosters = $j['rosters'] ?? null;
            if (!$rosters) {
                $fail++;
                if ($fail === 1) log_line("Aviso jugadores $code: " . ($j === null ? ($GLOBALS['ESPN_LAST'] ?? 'sin respuesta') : 'partido sin planilla'));
                if ($j !== null) $done[$g['id']] = 1; // ESPN no tiene planilla de ese partido: no reintentar
                if ($fail >= 8) break;
                continue;
            }
            foreach ($rosters as $r) {
                $tid = (string)($r['team']['id'] ?? '');
                $isHome = ($r['homeAway'] ?? '') === 'home' || $tid === $g['h'];
                if ($tid === '') $tid = $isHome ? $g['h'] : $g['a'];
                $opp = $isHome ? $g['a'] : $g['h'];
                foreach ($r['roster'] ?? [] as $pl) {
                    $starter = !empty($pl['starter']);
                    $played = $starter || !empty($pl['subbedIn']);
                    $stats = $pl['stats'] ?? [];
                    if (!$played && espn_stat($stats, ['appearances']) < 1) continue;
                    $ath = $pl['athlete'] ?? [];
                    $pid = (string)($ath['id'] ?? '');
                    if ($pid === '') continue;
                    $p = $db['players'][$pid] ?? ['h' => []];
                    $p['n'] = $ath['displayName'] ?? ($p['n'] ?? '?');
                    $p['ph'] = $ath['headshot']['href'] ?? ($p['ph'] ?? '');
                    $p['pos'] = $pl['position']['abbreviation'] ?? ($p['pos'] ?? '');
                    $p['t'] = $tid;
                    // [fecha, rival, local, titular, goles, asist, tiros, al arco, faltas, amarillas, rojas, atajadas]
                    $p['h'][] = [$g['ts'], $opp, $isHome ? 1 : 0, $starter ? 1 : 0,
                        espn_stat($stats, ['totalGoals', 'G']), espn_stat($stats, ['goalAssists', 'A']),
                        espn_stat($stats, ['totalShots', 'SH']), espn_stat($stats, ['shotsOnTarget', 'ST']),
                        espn_stat($stats, ['foulsCommitted', 'FC']), espn_stat($stats, ['yellowCards', 'YC']),
                        espn_stat($stats, ['redCards', 'RC']), espn_stat($stats, ['saves', 'SV'])];
                    $db['players'][$pid] = $p;
                }
            }
            $done[$g['id']] = 1;
            $ok++;
        }
        foreach ($db['players'] as &$p) usort($p['h'], fn($a, $b) => $a[0] <=> $b[0]);
        unset($p);
        $db['done'] = array_keys($done);
        $db['updated'] = $now;
        $db['league'] = $code;
        file_put_contents("$path.tmp", json_encode($db, JSON_UNESCAPED_UNICODE));
        rename("$path.tmp", $path);
        $left = max(0, count($pending) - $ok - $fail);
        $logs[] = "$code $ok" . ($left ? " (faltan $left)" : '');
        $teamsOut[$code] = array_map(fn($t) => $t['n'], $db['teams']);
    }
    if ($logs) log_line('OK jugadores fútbol (planillas nuevas): ' . implode(', ', $logs));
    return $teamsOut;
}

/** Palabras de un nombre de club sin acentos ni relleno (FC, CF, Club...). */
function club_tokens(string $n): array
{
    $n = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n) ?: $n);
    $n = preg_replace('/[^a-z0-9 ]/', ' ', $n);
    $stop = ['fc', 'cf', 'afc', 'sc', 'ac', 'as', 'ss', 'ssc', 'club', 'de', 'del', 'la', 'le', 'the', 'and', 'e', 'cd', 'ud', 'rc', 'sv', 'vfb', 'vfl', 'tsg', 'fk', 'sl', 'sad', 'calcio', 'football', 'futbol', '1', '04', '05', '1899', '1900', '1901', '1907', '1909', '1913', '1846', '1848', 'bc', 'ogc', 'stade', 'og', 'us', 'acf', 'fsv', 'sport', 'balompie', 'ca', 'rcd', 'losc'];
    return array_values(array_diff(array_filter(explode(' ', $n)), $stop));
}

/** Busca el id ESPN del equipo con el nombre más parecido. */
function club_match(string $name, array $teams): ?string
{
    $a = club_tokens($name);
    if (!$a) return null;
    $best = null; $bestScore = 0;
    foreach ($teams as $id => $tn) {
        $b = club_tokens($tn);
        if (!$b) continue;
        $common = 0; $first = 0;
        foreach ($a as $i => $w) {
            foreach ($b as $v) {
                if ($w === $v || (strlen($w) >= 4 && strlen($v) >= 4 && (str_starts_with($w, $v) || str_starts_with($v, $w)))) { $common++; if ($i === 0) $first = 1; break; }
            }
        }
        // la primera palabra pesa más (Espanyol de Barcelona -> Espanyol, no Barcelona)
        $score = $common / max(count($a), count($b)) + $common * 0.01 + $first * 0.05;
        if ($score > $bestScore) { $bestScore = $score; $best = (string)$id; }
    }
    return $bestScore >= 0.34 ? $best : null;
}
