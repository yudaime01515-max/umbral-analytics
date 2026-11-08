<?php
/**
 * Jugadores de fútbol desde ESPN (API pública no oficial, sin clave).
 * Por cada liga guarda public/data/fut/<CODIGO>.json con las estadísticas
 * partido a partido de cada jugador (goles, asistencias, tiros, tarjetas...).
 * Si ESPN falla, se conserva lo anterior y el resto de la web sigue igual.
 */

const ESPN_SOCCER_SLUGS = [
    'PL' => 'eng.1', 'PD' => 'esp.1', 'SA' => 'ita.1', 'BL1' => 'ger.1', 'FL1' => 'fra.1',
    'CL' => 'uefa.champions', 'PPL' => 'por.1', 'DED' => 'ned.1', 'ELC' => 'eng.2',
    'PER' => 'per.1', 'ARG' => 'arg.1', 'BRA' => 'bra.1', 'MEX' => 'mex.1', 'COL' => 'col.1', 'CHI' => 'chi.1',
    'LIB' => 'conmebol.libertadores', 'SUD' => 'conmebol.sudamericana', 'MLS' => 'usa.1', 'UEL' => 'uefa.europa',
    'UECL' => 'uefa.europa.conf', 'TUR' => 'tur.1', 'SCO' => 'sco.1', 'BEL' => 'bel.1', 'KSA' => 'ksa.1',
];

function espn_soccer_base(string $slug): string
{
    $b = getenv('ESPN_SOCCER_BASE') ?: 'https://site.api.espn.com/apis/site/v2/sports/soccer';
    return "$b/$slug";
}

/** Partidos de un día (formato ESPN: AAAAMMDD). */
function espn_soccer_day(string $slug, string $ymd): ?array
{
    $j = espn_fetch(espn_soccer_base($slug) . "/scoreboard?dates=$ymd");
    if ($j === null) { usleep(500000); $j = espn_fetch(espn_soccer_base($slug) . "/scoreboard?dates=$ymd"); }
    return $j === null ? null : ($j['events'] ?? []);
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
 * Estadísticas de equipo de un partido (córners, amarillas, rojas, remates, al arco, faltas).
 * Devuelve [fecha, idLocal, idVisita, [c,y,r,sh,sot,f] local, [..] visita] o null.
 * Usa el cuadro de equipos de ESPN; si falta un dato lo suma desde los jugadores (los córners no).
 */
function espn_tm_from_summary(array $j, ?array $g = null): ?array
{
    $h = $g['h'] ?? null; $a = $g['a'] ?? null; $ts = $g['ts'] ?? null;
    $comp = $j['header']['competitions'][0] ?? null;
    if ($comp) {
        foreach ($comp['competitors'] ?? [] as $c) {
            $id = (string)($c['team']['id'] ?? $c['id'] ?? '');
            if (($c['homeAway'] ?? '') === 'home') $h = $h ?: $id; else $a = $a ?: $id;
        }
        $ts = $ts ?: (int)strtotime($comp['date'] ?? '');
    }
    if (!$h || !$a || !$ts) return null;
    $want = ['c' => ['wonCorners', 'cornerKicks', 'corners'], 'y' => ['yellowCards'], 'r' => ['redCards'],
             'sh' => ['totalShots', 'shots'], 'sot' => ['shotsOnTarget'], 'f' => ['foulsCommitted', 'fouls']];
    $box = [$h => [], $a => []];
    foreach ($j['boxscore']['teams'] ?? [] as $t) {
        $id = (string)($t['team']['id'] ?? '');
        if (!isset($box[$id])) continue;
        foreach ($t['statistics'] ?? [] as $st) {
            foreach ($want as $k => $names) if (in_array($st['name'] ?? '', $names, true)) {
                $v = $st['displayValue'] ?? $st['value'] ?? null;
                if ($v !== null && $v !== '' && is_numeric(str_replace(',', '.', (string)$v))) $box[$id][$k] = (int)round((float)$v);
            }
        }
    }
    // respaldo: sumar desde los jugadores
    foreach ($j['rosters'] ?? [] as $r) {
        $id = (string)($r['team']['id'] ?? '');
        if (!isset($box[$id])) continue;
        $sum = ['y' => 0, 'r' => 0, 'sh' => 0, 'sot' => 0, 'f' => 0];
        foreach ($r['roster'] ?? [] as $pl) {
            $s = $pl['stats'] ?? [];
            $sum['y'] += espn_stat($s, ['yellowCards']); $sum['r'] += espn_stat($s, ['redCards']);
            $sum['sh'] += espn_stat($s, ['totalShots']); $sum['sot'] += espn_stat($s, ['shotsOnTarget']); $sum['f'] += espn_stat($s, ['foulsCommitted']);
        }
        foreach ($sum as $k => $v) if (!isset($box[$id][$k])) $box[$id][$k] = $v;
    }
    $row = function ($b) { return [$b['c'] ?? -1, $b['y'] ?? -1, $b['r'] ?? -1, $b['sh'] ?? -1, $b['sot'] ?? -1, $b['f'] ?? -1]; };
    if (!$box[$h] && !$box[$a]) return null;
    return [$ts, $h, $a, $row($box[$h]), $row($box[$a])];
}

/**
 * Actualiza los archivos de jugadores de todas las ligas.
 * Devuelve [codigo => [espnTeamId => nombre]] para enlazar equipos con los partidos.
 */
function futbol_players_update(array $cfg, array $codes, string $dataDir, array $matchTs = []): array
{
    $now = (int)($GLOBALS['UMBRAL_NOW'] ?? time());
    $dir = "$dataDir/fut";
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $budget = (int)(getenv('FUT_MAX_BOX') ?: 400); // planillas por ejecución (en total)
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
        $calYear = in_array($code, ['PER', 'ARG', 'BRA', 'COL', 'CHI', 'LIB', 'SUD', 'MLS'], true); // ligas de año calendario
        $season = $calYear ? $y : (((int)gmdate('n', $now) >= 7) ? $y : $y - 1);
        if (($db['season'] ?? null) !== $season) $db = ['season' => $season, 'teams' => [], 'players' => [], 'done' => [], 'queue' => [], 'days' => []];
        $db += ['tm' => [], 'tmx' => []];
        $done = array_flip(array_map('strval', $db['done'] ?? []));

        // Días con partidos terminados (según football-data) que aún no se revisaron en ESPN.
        // ESPN agrupa por fecha de EE. UU., así que se pide la fecha en UTC y la de 5 horas antes.
        $daysDone = array_flip($db['days'] ?? []);
        $queue = $db['queue'] ?? [];
        $inQueue = array_flip(array_column($queue, 'id'));
        $days = [];
        foreach ($matchTs[$code] ?? [] as $ts) {
            if ($ts > $now - 3 * 3600) continue;
            $days[gmdate('Ymd', $ts)] = 1;
            $days[gmdate('Ymd', $ts - 5 * 3600)] = 1;
        }
        $days = array_keys(array_diff_key($days, $daysDone));
        sort($days);
        $dayErr = 0; $daysRead = 0;
        foreach ($days as $ymd) {
            if ($dayErr >= 3) break;
            $events = espn_soccer_day($slug, (string)$ymd);
            usleep(200000);
            if ($events === null) { $dayErr++; continue; }
            $daysRead++;
            $allDone = true;
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
                $fin = !empty($st['completed']) && ($st['state'] ?? '') === 'post';
                if (!$fin) { if (($st['state'] ?? 'pre') !== 'post') $allDone = false; continue; }
                $eid = (string)$ev['id'];
                if (isset($done[$eid]) || isset($inQueue[$eid])) continue;
                $home = $away = null;
                foreach ($c['competitors'] ?? [] as $t) { if (($t['homeAway'] ?? '') === 'home') $home = $t; else $away = $t; }
                if (!$home || !$away) continue;
                $queue[] = ['id' => $eid, 'ts' => (int)strtotime($ev['date'] ?? ''),
                    'h' => (string)($home['team']['id'] ?? $home['id']), 'a' => (string)($away['team']['id'] ?? $away['id'])];
                $inQueue[$eid] = 1;
            }
            if ($allDone) $daysDone[$ymd] = 1;
        }
        if ($dayErr && !$daysRead) {
            log_line("ERROR jugadores $code (ESPN): " . ($GLOBALS['ESPN_LAST'] ?? 'sin respuesta'));
        }
        $pending = $queue;
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
            $tmRow = espn_tm_from_summary($j, $g);
            if ($tmRow) $db['tm'][$g['id']] = $tmRow;
            $done[$g['id']] = 1;
            $ok++;
        }
        // Partidos ya leídos antes de tener estadísticas de equipo: completarlos poco a poco
        $bf = 0;
        foreach (array_keys($done) as $eid) {
            if ($budget <= 0 || $bf >= (int)(getenv('FUT_MAX_BACKFILL') ?: 40)) break;
            if (isset($db['tm'][$eid]) || isset($db['tmx'][$eid])) continue;
            $budget--; $bf++;
            $j = espn_fetch(espn_soccer_base($slug) . '/summary?event=' . urlencode((string)$eid));
            usleep((int)(getenv('ESPN_PAUSE_MS') ?: 350) * 1000);
            if ($j === null) break;
            $tmRow = espn_tm_from_summary($j);
            if ($tmRow) $db['tm'][$eid] = $tmRow; else $db['tmx'][$eid] = 1;
        }
        foreach ($db['players'] as &$p) usort($p['h'], fn($a, $b) => $a[0] <=> $b[0]);
        unset($p);
        $db['queue'] = array_values(array_filter($queue, fn($q) => !isset($done[$q['id']])));
        $db['days'] = array_map('strval', array_keys($daysDone));
        $db['done'] = array_keys($done);
        $db['updated'] = $now;
        $db['league'] = $code;
        file_put_contents("$path.tmp", json_encode($db, JSON_UNESCAPED_UNICODE));
        rename("$path.tmp", $path);
        $left = count($db['queue']);
        $logs[] = "$code $ok" . ($left ? " (faltan $left)" : '') . ($bf ? " +$bf eq" : '');
        $teamsOut[$code] = array_map(fn($t) => $t['n'], $db['teams']);
        $GLOBALS['FUT_TM'][$code] = $db['tm'];
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


/* ---------- mercados de córners, tarjetas y remates ---------- */
const FUT_STAT = [ // clave => [índice en la fila, nombre, dispersión (más bajo = más variable)]
    'c' => [0, 'córners', 9], 'y' => [1, 'tarjetas amarillas', 6], 'sh' => [3, 'remates', 14], 'sot' => [4, 'remates a portería', 10],
];

/** Probabilidad de que una binomial negativa de media $mu supere $line (o quede debajo). */
function nb_over(float $mu, float $k, float $line): float
{
    $p = $k / ($k + $mu); $cdf = 0; $pk = $p ** $k; // P(0)
    $n = (int)floor($line);
    for ($x = 0; $x <= $n; $x++) { if ($x > 0) $pk *= ($x - 1 + $k) / $x * (1 - $p); $cdf += $pk; }
    return max(0.0, min(1.0, 1 - $cdf));
}

/** Promedios por partido de un equipo: [a favor, en contra] de cada estadística (últimos 10). */
function fut_team_avgs(array $tm, string $team, int $before): ?array
{
    $rows = array_filter($tm, fn($r) => ($r[1] === $team || $r[2] === $team) && $r[0] < $before);
    usort($rows, fn($a, $b) => $b[0] <=> $a[0]);
    $rows = array_slice($rows, 0, 10);
    if (count($rows) < 3) return null;
    $out = [];
    foreach (FUT_STAT as $k => [$i]) {
        $for = []; $ag = [];
        foreach ($rows as $r) {
            $mine = $r[1] === $team ? $r[3] : $r[4]; $theirs = $r[1] === $team ? $r[4] : $r[3];
            if ($mine[$i] >= 0 && $theirs[$i] >= 0) { $for[] = $mine[$i]; $ag[] = $theirs[$i]; }
        }
        $out[$k] = count($for) >= 3 ? [array_sum($for) / count($for), array_sum($ag) / count($ag), count($for)] : null;
    }
    return $out;
}

/** Mercados extra de un partido de fútbol (necesita los ids ESPN de los dos equipos). */
function fut_stat_markets(array $m, array $tm): array
{
    [$eh, $ea] = $m['et'];
    $H = fut_team_avgs($tm, (string)$eh, $m['ts']); $A = fut_team_avgs($tm, (string)$ea, $m['ts']);
    if (!$H || !$A) return [];
    $mk = []; $exp = [];
    foreach (FUT_STAT as $k => [$i, $name, $disp]) {
        if (!$H[$k] || !$A[$k]) continue;
        $muH = ($H[$k][0] + $A[$k][1]) / 2; $muA = ($A[$k][0] + $H[$k][1]) / 2; $mu = $muH + $muA;
        $exp[$k] = [$mu, $muH, $muA];
        // líneas totales alrededor de lo esperado
        $base = floor($mu) + 0.5;
        foreach ([-3, -2, -1, 0, 1, 2, 3] as $d) {
            $line = $base + $d; if ($line < 0.5) continue;
            $po = nb_over($mu, $disp * 1.6, $line);
            $mk[] = mk('Más de ' . $line . ' ' . $name, $po, ['st', $k, 'tot', 'over', $line]);
            $mk[] = mk('Menos de ' . $line . ' ' . $name, 1 - $po, ['st', $k, 'tot', 'under', $line]);
        }
        // por equipo (solo córners y remates a portería)
        if ($k === 'c' || $k === 'sot') {
            foreach ([['home', $muH, $m['home']], ['away', $muA, $m['away']]] as [$side, $mus, $tn]) {
                foreach ([-1, 0, 1] as $d) {
                    $line = floor($mus) + 0.5 + $d; if ($line < 0.5) continue;
                    $po = nb_over($mus, $disp, $line);
                    $mk[] = mk("$tn: más de $line $name", $po, ['st', $k, $side, 'over', $line]);
                }
            }
        }
    }
    return ['markets' => $mk, 'exp' => $exp];
}

/** Busca las estadísticas finales de un partido ya jugado (por ids ESPN y fecha). */
function fut_final_stats(array $tm, array $et, int $ts): ?array
{
    foreach ($tm as $r) {
        if ((string)$r[1] === (string)$et[0] && (string)$r[2] === (string)$et[1] && abs($r[0] - $ts) < 36 * 3600) return $r;
    }
    return null;
}

/** ¿Se cumplió un mercado de estadística? null = todavía no hay dato. */
function settle_stat(array $rule, array $row): ?bool
{
    [, $k, $side, $dir, $line] = $rule;
    $i = FUT_STAT[$k][0];
    $h = $row[3][$i]; $a = $row[4][$i];
    if ($h < 0 || $a < 0) return null;
    $v = $side === 'tot' ? $h + $a : ($side === 'home' ? $h : $a);
    return $dir === 'over' ? $v > $line : $v < $line;
}
