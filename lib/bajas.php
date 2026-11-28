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
