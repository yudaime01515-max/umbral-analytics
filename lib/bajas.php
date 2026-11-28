<?php
/**
 * Bajas NBA: el cerebro lee el reporte oficial de lesiones (ESPN) y ajusta solo el pronóstico.
 *
 * 1) Equipo: si le faltan jugadores que anotan, baja sus puntos esperados. Eso mueve ganador,
 *    hándicap y totales. No se descuenta todo lo que anotaba el lesionado: sus compañeros juegan
 *    más minutos y recuperan cerca del 70%, así que el equipo pierde ~30% de esos puntos.
 * 2) Compañeros: lo que dejan los lesionados (puntos, rebotes, asistencias, triples, tiros libres)
 *    se reparte entre los que sí juegan, según lo que ya aporta cada uno. Sus líneas suben.
 * 3) Si el jugador ya faltó en los últimos partidos, su ausencia ya está en la fuerza del equipo y
 *    se descuenta menos (para no contarla dos veces).
 */

const BAJA_PESO = ['Out' => 1.0, 'Out For Season' => 1.0, 'Suspension' => 1.0, 'Doubtful' => 0.8, 'Questionable' => 0.4, 'Day-To-Day' => 0.3];
const BAJA_REPARTO = 0.7;   // parte de la producción del lesionado que absorben sus compañeros
const BAJA_TOPE = 12.0;     // máximo de puntos que se le quitan a un equipo

function baja_key(string $n): string
{
    $n = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $n) ?: $n);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $n));
}

/** Lee el reporte y calcula, por equipo, cuánto pierde y cuánto gana cada compañero. */
function nba_bajas(array $db, int $now): array
{
    $out = ['teams' => [], 'skip' => [], 'boost' => []];
    $j = espn_fetch(espn_base() . '/injuries');
    if (!$j || empty($j['injuries'])) { log_line('Aviso bajas NBA: sin reporte (' . ($GLOBALS['ESPN_LAST'] ?? '?') . ')'); return $out; }

    // jugadores por nombre y por equipo, con sus promedios de los últimos 15 partidos jugados
    $players = $db['players'] ?? [];
    $byName = []; $byTeam = [];
    foreach ($players as $pid => $p) {
        $h = array_values(array_filter($p['h'] ?? [], fn($g) => $g[0] < $now && $g[3] >= 1));
        if (count($h) < 3) continue;
        usort($h, fn($a, $b) => $b[0] <=> $a[0]);
        $r = array_slice($h, 0, 15); $n = count($r);
        $avg = fn($i) => array_sum(array_map(fn($g) => (float)($g[$i] ?? 0), $r)) / $n;
        $info = ['id' => (string)$pid, 'n' => $p['n'], 't' => (string)($p['t'] ?? ''), 'min' => $avg(3), 'pts' => $avg(4), 'reb' => $avg(5), 'ast' => $avg(6), 'tri' => $avg(7), 'ft' => $avg(10), 'ts' => array_column($h, 0)];
        $byName[baja_key($p['n'])] = $info;
        $byTeam[$info['t']][] = $info;
    }

    foreach ($j['injuries'] as $t) {
        $tid = (string)($t['id'] ?? ($t['team']['id'] ?? ''));
        if ($tid === '') continue;
        // últimos 20 partidos del equipo (fechas en las que jugó alguno de sus jugadores)
        $teamTs = [];
        foreach ($byTeam[$tid] ?? [] as $pl) foreach ($pl['ts'] as $x) $teamTs[$x] = 1;
        krsort($teamTs); $last20 = array_slice(array_keys($teamTs), 0, 20);

        $lost = ['pts' => 0, 'reb' => 0, 'ast' => 0, 'tri' => 0, 'ft' => 0]; $net = 0; $list = [];
        foreach ($t['injuries'] ?? [] as $i) {
            $st = (string)($i['status'] ?? '');
            $w = BAJA_PESO[$st] ?? 0;
            $name = (string)($i['athlete']['displayName'] ?? '');
            if ($w <= 0 || $name === '') continue;
            $key = baja_key($name);
            if ($w >= 0.4) $out['skip'][$key] = $st; // sus mercados no se publican
            $pl = $byName[$key] ?? null;
            if (!$pl || $pl['min'] < 12) { $list[] = [$name, $st, 0]; continue; } // suplente de pocos minutos: casi no cambia nada
            // si ya venía faltando, el equipo ya juega sin él: se descuenta menos
            $played = $last20 ? count(array_intersect($last20, $pl['ts'])) / count($last20) : 1;
            $f = $w * max(0.25, $played);
            foreach ($lost as $k => $_) $lost[$k] += $pl[$k] * $f;
            $net += $pl['pts'] * $f * (1 - BAJA_REPARTO);
            $list[] = [$name, $st, round($pl['pts'], 1)];
        }
        if (!$list) continue;
        $net = min(BAJA_TOPE, $net);
        $out['teams'][$tid] = ['pts' => round($net, 2), 'list' => $list];

        // reparto entre los que juegan (rotación de 15+ minutos), según lo que ya aporta cada uno
        $rot = array_values(array_filter($byTeam[$tid] ?? [], fn($pl) => $pl['min'] >= 15 && !isset($out['skip'][baja_key($pl['n'])])));
        foreach (['pts', 'reb', 'ast', 'tri', 'ft'] as $k) {
            $tot = array_sum(array_column($rot, $k));
            if ($tot <= 0 || $lost[$k] <= 0) continue;
            foreach ($rot as $pl) $out['boost'][$pl['id']][$k] = round(($out['boost'][$pl['id']][$k] ?? 0) + $lost[$k] * BAJA_REPARTO * $pl[$k] / $tot, 2);
        }
    }
    $nT = count($out['teams']);
    log_line("OK bajas NBA: $nT equipos con bajas, " . count($out['skip']) . ' jugadores fuera o en duda');
    return $out;
}

/** Línea para el detalle del partido: quién falta y cuánto pesa. */
function nba_bajas_exp(string $team, array $b): array
{
    $nm = array_map(fn($x) => $x[0] . ($x[1] === 'Out' || $x[1] === 'Out For Season' ? '' : ' (duda)'), array_slice($b['list'], 0, 4));
    return ["Bajas ($team)", implode(', ', $nm) . ($b['pts'] > 0.2 ? ' · −' . number_format($b['pts'], 1) . ' pts esperados' : '')];
}

/* ======================= NFL y NHL: reporte oficial de lesiones ======================= */
// Cuánto pesa cada posición. NFL en puntos (ataque: puntos que deja de anotar; defensa: puntos de más del rival).
// NHL en proporción de goles. Si está en la reserva de lesionados (IR) hace semanas, ya está en la fuerza del equipo: pesa poco.
const LIGA_PESO = ['Out' => 1.0, 'Suspension' => 1.0, 'Doubtful' => 0.8, 'Questionable' => 0.3, 'Day-To-Day' => 0.35, 'Injured Reserve' => 0.15, 'Out For Season' => 0.1];
const NFL_POS = ['QB' => [6.0, 0], 'RB' => [0.8, 0], 'WR' => [1.0, 0], 'TE' => [0.6, 0], 'OT' => [0.5, 0], 'T' => [0.5, 0], 'G' => [0.3, 0], 'OG' => [0.3, 0], 'C' => [0.4, 0], 'OL' => [0.4, 0], 'K' => [0.6, 0],
                'DE' => [0, 0.5], 'DT' => [0, 0.4], 'DL' => [0, 0.4], 'NT' => [0, 0.4], 'LB' => [0, 0.4], 'OLB' => [0, 0.4], 'ILB' => [0, 0.4], 'MLB' => [0, 0.4], 'CB' => [0, 0.6], 'S' => [0, 0.4], 'FS' => [0, 0.4], 'SS' => [0, 0.4], 'DB' => [0, 0.5]];
const NHL_POS = ['C' => [0.035, 0], 'LW' => [0.03, 0], 'RW' => [0.03, 0], 'F' => [0.03, 0], 'D' => [0, 0.025], 'G' => [0, 0.04]];

function liga_bajas(string $path): array
{
    $nfl = str_starts_with($path, 'football');
    $j = espn_fetch('https://site.api.espn.com/apis/site/v2/sports/' . $path . '/injuries');
    if (!$j || empty($j['injuries'])) { log_line("Aviso bajas $path: sin reporte (" . ($GLOBALS['ESPN_LAST'] ?? '?') . ')'); return []; }
    $out = [];
    foreach ($j['injuries'] as $t) {
        $tid = (string)($t['id'] ?? ''); if ($tid === '') continue;
        $att = 0; $opp = 0; $list = [];
        foreach ($t['injuries'] ?? [] as $i) {
            $w = LIGA_PESO[(string)($i['status'] ?? '')] ?? 0; if ($w <= 0) continue;
            $pos = strtoupper((string)($i['athlete']['position']['abbreviation'] ?? ''));
            $pw = ($nfl ? NFL_POS : NHL_POS)[$pos] ?? null; if (!$pw) continue;
            $att += $pw[0] * $w; $opp += $pw[1] * $w;
            if ($w >= 0.3 && ($pw[0] + $pw[1]) * $w >= ($nfl ? 0.4 : 0.02)) $list[] = [(string)($i['athlete']['displayName'] ?? ''), (string)$i['status'], $pos, $pw[0] * $w + $pw[1] * $w];
        }
        if ($att <= 0 && $opp <= 0) continue;
        usort($list, fn($a, $b) => $b[3] <=> $a[3]);
        $out[$tid] = $nfl ? ['att' => -min(9.0, $att), 'opp' => min(5.0, $opp), 'list' => $list, 'unit' => 'pts']
                          : ['att' => -min(0.12, $att), 'opp' => min(0.10, $opp), 'list' => $list, 'unit' => '%'];
    }
    log_line("OK bajas $path: " . count($out) . ' equipos con bajas que pesan');
    return $out;
}

/* ======================= Fútbol: alineaciones confirmadas ======================= */
// ESPN no publica lesionados de fútbol, pero sí la alineación titular ~1 hora antes. Se compara con los
// titulares de siempre (de las planillas que Umbral ya guarda): si falta un goleador, el equipo marca menos;
// si falta el arquero o defensas habituales, el rival marca un poco más.

function fut_db(string $code): array
{
    static $memo = [];
    if (isset($memo[$code])) return $memo[$code];
    $path = (defined('UMBRAL_DATA') ? UMBRAL_DATA : dirname(__DIR__) . '/data') . "/fut/$code.json";
    return $memo[$code] = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
}

/** id del partido en ESPN (los de football-data se buscan en el calendario de ESPN por nombre). */
function fut_espn_event(array $g, string $slug): ?string
{
    if (str_starts_with((string)$g['id'], 'futbol-espn-')) return substr((string)$g['id'], 12);
    static $days = [];
    foreach ([gmdate('Ymd', $g['ts']), gmdate('Ymd', $g['ts'] - 5 * 3600)] as $ymd) {
        $k = "$slug|$ymd";
        if (!array_key_exists($k, $days)) $days[$k] = espn_soccer_day($slug, $ymd) ?? [];
        foreach ($days[$k] as $ev) {
            $c = $ev['competitions'][0]['competitors'] ?? [];
            $names = [];
            foreach ($c as $t) $names[$t['homeAway'] ?? ''] = $t['team']['displayName'] ?? '';
            if (abs(strtotime($ev['date'] ?? '') - $g['ts']) > 3 * 3600) continue;
            if (club_match($g['home_full'] ?: $g['home'], ['h' => $names['home'] ?? '']) && club_match($g['away_full'] ?: $g['away'], ['a' => $names['away'] ?? ''])) return (string)$ev['id'];
        }
    }
    return null;
}

function fut_alineacion(array $g, string $code, string $slug, int $now): ?array
{
    if ($g['ts'] > $now + 100 * 60 || $g['ts'] < $now - 20 * 60) return null; // la alineación sale ~1 h antes
    $db = fut_db($code); if (empty($db['players'])) return null;
    $eid = fut_espn_event($g, $slug); if (!$eid) return null;
    $j = espn_fetch(espn_soccer_base($slug) . '/summary?event=' . urlencode($eid));
    $ros = $j['rosters'] ?? [];
    $res = [];
    foreach ($ros as $r) {
        $side = ($r['homeAway'] ?? '') === 'home' ? 'h' : (($r['homeAway'] ?? '') === 'away' ? 'a' : null);
        $tid = (string)($r['team']['id'] ?? '');
        $starters = []; $inRoster = [];
        foreach ($r['roster'] ?? [] as $p) { $id = (string)($p['athlete']['id'] ?? ''); if ($id === '') continue; $inRoster[$id] = 1; if (!empty($p['starter'])) $starters[$id] = 1; }
        if (!$side || $tid === '' || count($starters) < 11) continue; // todavía no hay alineación
        // últimos partidos del equipo y sus jugadores
        $mine = array_filter($db['players'], fn($p) => (string)($p['t'] ?? '') === $tid);
        $ts = [];
        foreach ($mine as $p) foreach ($p['h'] ?? [] as $row) if ($row[0] < $g['ts']) $ts[$row[0]] = 1;
        krsort($ts); $last = array_slice(array_keys($ts), 0, 10); $last8 = array_slice($last, 0, 8);
        if (count($last8) < 4) continue;
        $tot = 0; $info = [];
        foreach ($mine as $pid => $p) {
            $rows = array_values(array_filter($p['h'] ?? [], fn($row) => in_array($row[0], $last, true)));
            $starts = count(array_filter($rows, fn($row) => in_array($row[0], $last8, true) && !empty($row[3])));
            $c = array_sum(array_map(fn($row) => ($row[4] ?? 0) + 0.6 * ($row[5] ?? 0), $rows));
            $tot += $c;
            $info[(string)$pid] = ['n' => $p['n'] ?? '?', 'pos' => $p['pos'] ?? '', 'reg' => $starts >= max(4, (int)ceil(count($last8) * 0.6)), 'c' => $c];
        }
        $tot = max(1.0, $tot);
        $lossA = 0; $oppUp = 0; $miss = [];
        foreach ($info as $pid => $x) {
            if (!$x['reg'] || isset($starters[$pid])) continue;
            $w = isset($inRoster[$pid]) ? 0.7 : 1.0; // en el banco puede entrar después
            $lossA += $w * $x['c'] / $tot;
            if ($x['pos'] === 'G') $oppUp += 0.06 * $w; elseif ($x['pos'] === 'D') $oppUp += 0.025 * $w;
            if ($x['c'] / $tot >= 0.08 || in_array($x['pos'], ['G', 'D'], true)) $miss[] = [$x['n'], $x['c'] / $tot, isset($inRoster[$pid])];
        }
        usort($miss, fn($a, $b) => $b[1] <=> $a[1]);
        $res[$side] = ['att' => max(0.75, 1 - 0.6 * min(0.45, $lossA)), 'opp' => min(1.12, 1 + $oppUp), 'miss' => $miss];
    }
    return $res ?: null;
}

/** Línea para el detalle del partido. */
function fut_alineacion_exp(string $team, array $x): array
{
    if (!$x['miss']) return ["Alineación ($team)", 'confirmada, con sus titulares de siempre'];
    $nm = array_map(fn($m) => $m[0] . ($m[2] ? ' (al banco)' : ''), array_slice($x['miss'], 0, 4));
    $d = round((1 - $x['att']) * 100);
    return ["Alineación ($team)", 'sin ' . implode(', ', $nm) . ($d >= 2 ? " · −$d% goles" : '') . ($x['opp'] > 1.02 ? ' · el rival +' . round(($x['opp'] - 1) * 100) . '%' : '')];
}
